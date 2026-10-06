// Package archcheck checks the architecture kit's structural rules as a go/analysis analyzer.
package archcheck

import (
	"errors"
	"fmt"
	"go/ast"
	"go/token"
	"go/types"
	"os"
	"path"
	"path/filepath"
	"reflect"
	"slices"
	"sort"
	"strconv"
	"strings"

	"golang.org/x/tools/go/analysis"
)

// Options locate the architecture configuration and optional baseline.
// Relative paths resolve from the root of the Go module being analyzed.
type Options struct {
	Config   string `json:"config"`
	Baseline string `json:"baseline"`
}

// Violation fields other than Message form its baseline identity.
type Violation struct {
	Rule    string `json:"rule"`
	File    string `json:"file"`
	Symbol  string `json:"symbol"`
	Target  string `json:"target"`
	Message string `json:"message"`

	pos token.Pos
}

func (v Violation) key() string {
	return strings.Join([]string{v.Rule, v.File, v.Symbol, v.Target}, "|")
}

const doc = `check architecture kit rules against architecture.json

archcheck classifies every production package and file into an architectural
module and role, then reports dependencies, effects, and published surfaces that
the configuration forbids. Each diagnostic cites the kit's rule ID.`

// New returns an analyzer with fixed options, as the golangci-lint plugin uses.
func New(opts Options) *analysis.Analyzer {
	return &analysis.Analyzer{
		Name:       "archcheck",
		Doc:        doc,
		ResultType: reflect.TypeFor[[]Violation](),
		Run:        func(pass *analysis.Pass) (any, error) { return run(pass, opts) },
	}
}

// Analyzer reads its options from flags, for the standalone command and go vet.
var Analyzer = func() *analysis.Analyzer {
	var opts Options
	a := New(Options{})
	a.Run = func(pass *analysis.Pass) (any, error) { return run(pass, opts) }
	a.Flags.StringVar(&opts.Config, "config", "", "architecture configuration (default architecture.json at the module root)")
	a.Flags.StringVar(&opts.Baseline, "baseline", "", "optional violation baseline, relative to the module root")
	return a
}()

type check struct {
	pass        *analysis.Pass
	proj        *project
	rel         string
	found       []Violation
	boundarySet map[*types.TypeName]bool
}

func run(pass *analysis.Pass, opts Options) (any, error) {
	none := []Violation(nil)
	if strings.HasSuffix(pass.Pkg.Path(), "_test") || strings.HasSuffix(pass.Pkg.Path(), ".test") {
		return none, nil
	}
	var files []*ast.File
	for _, file := range pass.Files {
		if name := pass.Fset.File(file.Pos()).Name(); strings.HasSuffix(name, ".go") && !strings.HasSuffix(name, "_test.go") {
			files = append(files, file)
		}
	}
	if len(files) == 0 {
		return none, nil
	}
	root, err := moduleRoot(filepath.Dir(pass.Fset.File(files[0].Pos()).Name()))
	if err != nil {
		return none, err
	}
	proj, err := loadProject(root, opts)
	if err != nil {
		return none, err
	}
	rel, ok := proj.relative(pass.Pkg.Path())
	if !ok {
		return none, nil
	}
	c := &check{pass: pass, proj: proj, rel: rel}
	var names []string
	for _, file := range files {
		names = append(names, c.file(file))
	}
	reported := reconcile(c.found, proj.baseline, names, func(name string) token.Pos {
		return files[slices.Index(names, name)].Name.Pos()
	})
	for _, v := range reported {
		pass.Report(analysis.Diagnostic{Pos: v.pos, Category: v.Rule, Message: fmt.Sprintf("%s [%s -> %s]: %s", v.Rule, v.Symbol, v.Target, v.Message)})
	}
	return reported, nil
}

func moduleRoot(dir string) (string, error) {
	for {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir, nil
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return "", errors.New("no go.mod above the analyzed package")
		}
		dir = parent
	}
}

func (c *check) add(pos token.Pos, rule, file, symbol, target, message string) {
	c.found = append(c.found, Violation{rule, file, symbol, target, message, pos})
}

// role returns the classification of a production file, applying any file override.
func (c *check) role(name string) Package {
	pkg := c.proj.packages[c.rel]
	if override, ok := c.proj.config.Files[name]; ok {
		pkg.Role = override
	}
	return pkg
}

func (c *check) file(file *ast.File) string {
	name := path.Join(c.rel, filepath.Base(c.pass.Fset.File(file.Pos()).Name()))
	if _, classified := c.proj.packages[c.rel]; !classified {
		c.add(file.Name.Pos(), "R02", name, "package", c.rel, fmt.Sprintf(`unclassified production source; add a packages entry {"path": %q, "module": "<module>", "role": "<role>"}`, c.rel))
		return name
	}
	pkg := c.role(name)
	if pkg.Role == "test" {
		return name
	}
	pure := pkg.Role == "domain" || pkg.Role == "contract"
	purityRule := "R04"
	if pkg.Role == "contract" {
		purityRule = "R05"
	}
	config := c.proj.config
	for _, spec := range file.Imports {
		dependency, err := strconv.Unquote(spec.Path.Value)
		if err != nil {
			continue
		}
		add := func(rule, message string) { c.add(spec.Pos(), rule, name, "imports", dependency, message) }
		// embed has no initialization side effects; //go:embed requires the import.
		if spec.Name != nil && spec.Name.Name == "_" && dependency != "embed" && !slices.Contains([]string{"infrastructure", "shared-infrastructure", "composition"}, pkg.Role) {
			add("R06", "implicit initialization outside composition/adapters; move the blank import to composition or an adapter")
		}
		if local, ok := c.proj.relative(dependency); ok {
			target, exists := c.proj.packages[local]
			switch {
			case !exists:
				add("R02", fmt.Sprintf("imported package has no role classification; add a packages entry for %q", local))
			case pkg.Role == "domain" && target.Role == "contract" && target.Effectful:
				add("R04", "Domain depends on an effectful port; have Application call it and pass the result into Domain")
			case target.Role == "test":
				add("R02", "production code depends on test-only code; move the helper into production code or a _test.go file")
			case !allowed(pkg, target):
				rule := "R02"
				if target.Role == "composition" {
					rule = "R06"
				} else if pkg.Module != target.Module {
					rule = "R03"
				}
				add(rule, boundaryFix(pkg, target))
			case pkg.Role != "composition" && pkg.Module != target.Module && !slices.Contains(config.DependsOn[pkg.Module], target.Module):
				add("R03", fmt.Sprintf("module %s does not declare a dependency on %s; add it to depends_on if intended, or go through an existing dependency", pkg.Module, target.Module))
			}
		} else if pkg.Role == "contract" && dependency == "context" {
			// Effectful ports take a context; the marker keeps Domain from importing them.
			if !pkg.Effectful {
				add(purityRule, "contract imports context; if it declares effectful ports, mark it effectful so Domain cannot import it")
			}
		} else if pure && !slices.Contains(config.PureImports, dependency) {
			add(purityRule, fmt.Sprintf("external package has not been reviewed for pure-role use; add it to pure_imports after review, or move this code out of the %s role", pkg.Role))
		}
		if (pkg.Role == "application" || pkg.Role == "presentation") && c.storage(dependency) {
			add("R02", "storage access outside Infrastructure; put persistence behind a contract the Application defines and implement it in Infrastructure")
		}
	}
	for _, decl := range file.Decls {
		symbol := declarationName(c.pass.Fset, decl)
		if pkg.Public || pkg.Role == "contract" {
			for _, obj := range c.published(decl) {
				if bad := c.surface(obj.Type(), false, make(map[*types.TypeName]bool)); bad != "" {
					c.add(obj.Pos(), "R05", name, symbol, bad, "published signature exposes a private or unapproved external type; publish a boundary type in a contract or public package, or review the external package into pure_imports")
				}
				if bad := c.surface(obj.Type(), true, make(map[*types.TypeName]bool)); bad != "" {
					c.add(obj.Pos(), "R10", name, symbol, bad, "published schema contains an unstructured payload; define a typed schema")
				}
			}
		}
		if fn, ok := decl.(*ast.FuncDecl); ok && fn.Recv == nil && fn.Name.Name == "init" && config.ForbidInit {
			c.add(fn.Pos(), "R06", name, symbol, "init", "project forbids implicit init-time wiring; move the wiring into composition")
		}
		ast.Inspect(decl, func(node ast.Node) bool {
			id, ok := node.(*ast.Ident)
			if !ok {
				return true
			}
			obj := c.pass.TypesInfo.Uses[id]
			if obj == nil || obj.Pkg() == nil {
				return true
			}
			if pure && obj.Parent() == obj.Pkg().Scope() && slices.Contains(config.ForbiddenSymbols[obj.Pkg().Path()], obj.Name()) {
				c.add(id.Pos(), purityRule, name, symbol, obj.Pkg().Path()+"."+obj.Name(), "reference to a configured effectful symbol; move the effect to Application and pass in any value it produces")
			}
			if declared, ok := c.declaringFile(obj); ok && declared != name && !allowed(pkg, c.role(declared)) {
				c.add(id.Pos(), "R02", name, symbol, id.Name, "local reference crosses a configured file-role boundary: "+boundaryFix(pkg, c.role(declared)))
			}
			if pkg.Role == "infrastructure" || pkg.Role == "presentation" {
				c.adapterReference(id, obj, pkg, name, symbol)
			}
			return true
		})
		switch pkg.Role {
		case "domain":
			c.domainWrites(decl, name, symbol)
		case "composition":
			c.wiring(decl, name, symbol)
		}
		if pkg.Role != "composition" {
			c.tableOwnership(decl, pkg, name, symbol)
			c.transactionEffects(decl, name, symbol)
		}
	}
	return name
}

// declaringFile returns the file that declares a package-level object or method of this
// package, when the package has file-role overrides that could separate them.
func (c *check) declaringFile(obj types.Object) (string, bool) {
	if len(c.proj.config.Files) == 0 || obj.Pkg() != c.pass.Pkg {
		return "", false
	}
	if v, ok := obj.(*types.Var); ok && v.IsField() {
		return "", false
	}
	if obj.Parent() != nil && obj.Parent() != c.pass.Pkg.Scope() {
		return "", false // local declarations, labels, and import names
	}
	return path.Join(c.rel, filepath.Base(c.pass.Fset.Position(obj.Pos()).Filename)), true
}

// published returns the exported objects a declaration adds to the package's public surface.
func (c *check) published(decl ast.Decl) []types.Object {
	var objects []types.Object
	define := func(id *ast.Ident) {
		if obj := c.pass.TypesInfo.Defs[id]; obj != nil && id.IsExported() {
			objects = append(objects, obj)
		}
	}
	switch decl := decl.(type) {
	case *ast.FuncDecl:
		define(decl.Name)
	case *ast.GenDecl:
		for _, spec := range decl.Specs {
			switch spec := spec.(type) {
			case *ast.TypeSpec:
				define(spec.Name)
			case *ast.ValueSpec:
				for _, id := range spec.Names {
					define(id)
				}
			}
		}
	}
	return objects
}

// reconcile matches violations against the baseline by identity and count. Identity
// includes the file, so a package's own files hold every occurrence of its entries.
func reconcile(current, baseline []Violation, files []string, filePos func(string) token.Pos) []Violation {
	remaining := make(map[string]int)
	entries := make(map[string]Violation)
	for _, v := range baseline {
		if slices.Contains(files, v.File) {
			remaining[v.key()]++
			entries[v.key()] = v
		}
	}
	var reported []Violation
	for _, v := range current {
		if remaining[v.key()] > 0 {
			remaining[v.key()]--
		} else {
			reported = append(reported, v)
		}
	}
	for key, count := range remaining {
		if count > 0 {
			v := entries[key]
			reported = append(reported, Violation{"R16", v.File, v.Symbol, v.Target,
				fmt.Sprintf("remove %d resolved baseline occurrence(s) of %s", count, v.Rule), filePos(v.File)})
		}
	}
	sort.SliceStable(reported, func(i, j int) bool { return reported[i].pos < reported[j].pos })
	return reported
}
