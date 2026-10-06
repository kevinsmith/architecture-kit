package archcheck

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"sync"
)

type Package struct {
	Path      string `json:"path"`
	Module    string `json:"module"`
	Role      string `json:"role"`
	Effectful bool   `json:"effectful,omitempty"`
	Public    bool   `json:"public,omitempty"`
}

type Config struct {
	Module           string              `json:"module"`
	Packages         []Package           `json:"packages"`
	PureImports      []string            `json:"pure_imports"`
	ForbiddenSymbols map[string][]string `json:"forbidden_symbols"`
	ForbidInit       bool                `json:"forbid_init"`
	// Files override a package's role for colocated logical responsibilities.
	Files map[string]string `json:"files,omitempty"`
	// DependsOn lists the other architectural modules each module may depend on.
	DependsOn map[string][]string `json:"depends_on,omitempty"`
	// StorageImports are persistence packages that only Infrastructure may import.
	StorageImports []string `json:"storage_imports,omitempty"`
	// Datasets lists the tables each module owns (R09).
	Datasets map[string][]string `json:"datasets,omitempty"`
	// TransactionFunctions run a transaction callback; ExternalEffects are functions, methods,
	// or whole types whose calls perform effects (R13). Both use import/path.Name[.Method].
	TransactionFunctions []string `json:"transaction_functions,omitempty"`
	ExternalEffects      []string `json:"external_effects,omitempty"`
}

var roles = []string{"domain", "application", "presentation", "infrastructure", "contract", "shared-infrastructure", "composition", "test"}

// project is a validated configuration for one Go module root.
type project struct {
	config   Config
	packages map[string]Package
	owners   map[string]string
	baseline []Violation
}

func (p *project) relative(importPath string) (string, bool) {
	if importPath == p.config.Module {
		return ".", true
	}
	return strings.CutPrefix(importPath, p.config.Module+"/")
}

type cached struct {
	once    sync.Once
	project *project
	err     error
}

// Analysis runs once per package, so validated projects are cached by content.
var projects sync.Map

func loadProject(root string, opts Options) (*project, error) {
	configPath := resolve(root, opts.Config, "architecture.json")
	configData, err := os.ReadFile(configPath)
	if err != nil {
		return nil, err
	}
	var baselineData []byte
	if opts.Baseline != "" {
		if baselineData, err = os.ReadFile(resolve(root, opts.Baseline, "")); err != nil {
			return nil, err
		}
	}
	key := fmt.Sprintf("%s\x00%x\x00%x", root, sha256.Sum256(configData), sha256.Sum256(baselineData))
	entry, _ := projects.LoadOrStore(key, &cached{})
	c := entry.(*cached)
	c.once.Do(func() { c.project, c.err = newProject(root, configPath, configData, baselineData) })
	return c.project, c.err
}

func resolve(root, name, fallback string) string {
	if name == "" {
		name = fallback
	}
	if filepath.IsAbs(name) {
		return name
	}
	return filepath.Join(root, name)
}

func newProject(root, configPath string, configData, baselineData []byte) (*project, error) {
	var config Config
	if err := decodeStrict(configData, &config); err != nil {
		return nil, fmt.Errorf("%s: %w", configPath, err)
	}
	p := &project{config: config, packages: make(map[string]Package), owners: make(map[string]string)}
	if err := p.validate(root); err != nil {
		return nil, fmt.Errorf("%s: %w", configPath, err)
	}
	if baselineData != nil {
		if err := decodeStrict(baselineData, &p.baseline); err != nil {
			return nil, fmt.Errorf("baseline: %w", err)
		}
		for _, v := range p.baseline {
			if _, err := os.Stat(filepath.Join(root, v.File)); err != nil {
				return nil, fmt.Errorf("R16 baseline entry for missing file %s: remove the resolved entry", v.File)
			}
		}
	}
	return p, nil
}

func decodeStrict(data []byte, value any) error {
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(value); err != nil {
		return err
	}
	if err := decoder.Decode(new(any)); err != io.EOF {
		return errors.New("expected exactly one JSON value")
	}
	return nil
}

var moduleDeclaration = regexp.MustCompile(`(?m)^module\s+"?([^\s"]+)"?\s*$`)

func (p *project) validate(root string) error {
	config := p.config
	moduleFile, err := os.ReadFile(filepath.Join(root, "go.mod"))
	if err != nil {
		return err
	}
	declaration := moduleDeclaration.FindSubmatch(moduleFile)
	if len(declaration) != 2 || config.Module == "" || string(declaration[1]) != config.Module {
		return errors.New("configuration module must match go.mod")
	}
	modules := make(map[string]bool)
	for _, pkg := range config.Packages {
		if pkg.Path == "" || pkg.Path != path.Clean(pkg.Path) || strings.HasPrefix(pkg.Path, "/") || pkg.Path == ".." || strings.HasPrefix(pkg.Path, "../") || pkg.Module == "" {
			return fmt.Errorf("invalid package classification: %+v", pkg)
		}
		if !slices.Contains(roles, pkg.Role) {
			return fmt.Errorf("unknown role %q", pkg.Role)
		}
		if pkg.Effectful && pkg.Role != "contract" {
			return fmt.Errorf("effectful marker is only valid on contract packages: %s", pkg.Path)
		}
		if pkg.Public && pkg.Role != "application" {
			return fmt.Errorf("public API marker is only valid on Application packages: %s", pkg.Path)
		}
		if _, exists := p.packages[pkg.Path]; exists {
			return fmt.Errorf("duplicate classification: %s", pkg.Path)
		}
		p.packages[pkg.Path] = pkg
		modules[pkg.Module] = true
	}
	for from, targets := range config.DependsOn {
		if !modules[from] {
			return fmt.Errorf("depends_on names unknown module %q", from)
		}
		for _, to := range targets {
			if !modules[to] || to == from {
				return fmt.Errorf("invalid module dependency %s -> %s", from, to)
			}
		}
	}
	if cycle := moduleCycle(config.DependsOn); cycle != "" {
		return fmt.Errorf("module dependency cycle: %s", cycle)
	}
	for module, tables := range config.Datasets {
		if !modules[module] {
			return fmt.Errorf("datasets names unknown module %q", module)
		}
		for _, table := range tables {
			if table == "" {
				return fmt.Errorf("datasets for %s contains an empty table name", module)
			}
			key := strings.ToLower(table)
			if owner, ok := p.owners[key]; ok {
				return fmt.Errorf("table %q needs exactly one owner; claimed by %s and %s", table, owner, module)
			}
			p.owners[key] = module
		}
	}
	for _, name := range slices.Concat(config.TransactionFunctions, config.ExternalEffects) {
		if !validQualifiedName(name) {
			return fmt.Errorf("invalid qualified name %q; use import/path.Name or import/path.Type.Method", name)
		}
	}
	for file, role := range config.Files {
		pkg, ok := p.packages[path.Dir(file)]
		if !ok || !slices.Contains([]string{"domain", "application", "presentation", "infrastructure", "composition"}, role) || file != path.Clean(file) || strings.HasPrefix(file, "../") || !strings.HasSuffix(file, ".go") || strings.HasSuffix(file, "_test.go") {
			return fmt.Errorf("invalid file classification: %s (%s)", file, role)
		}
		if pkg.Role == "contract" || pkg.Role == "test" || pkg.Role == "shared-infrastructure" || pkg.Public {
			return fmt.Errorf("file overrides require a private module package: %s", file)
		}
		if _, err := os.Stat(filepath.Join(root, file)); err != nil {
			return fmt.Errorf("file classification: %w", err)
		}
	}
	// Stale entries fail across every build variant, not only the one being analyzed.
	for dir := range p.packages {
		entries, err := os.ReadDir(filepath.Join(root, dir))
		if err != nil {
			return fmt.Errorf("stale package classification %s: %w", dir, err)
		}
		if !slices.ContainsFunc(entries, func(e os.DirEntry) bool {
			return !e.IsDir() && strings.HasSuffix(e.Name(), ".go") && !strings.HasSuffix(e.Name(), "_test.go")
		}) {
			return fmt.Errorf("stale package classification %s: no production Go source", dir)
		}
	}
	return nil
}
