package archcheck

import (
	"bytes"
	"go/ast"
	"go/format"
	"go/token"
	"maps"
	"slices"
	"strings"
)

func allowed(from, to Package) bool {
	if from.Role == "composition" {
		return true
	}
	if to.Role == "contract" {
		return true
	}
	if from.Role == "contract" {
		return false
	}
	if from.Role == "shared-infrastructure" {
		return to.Role == "shared-infrastructure" && from.Module == to.Module
	}
	if to.Public && (from.Role == "application" || from.Role == "infrastructure") {
		return true
	}
	if from.Module != to.Module {
		return false
	}
	switch from.Role {
	case "domain":
		return to.Role == "domain"
	case "application":
		return to.Role == "application" || to.Role == "domain"
	case "presentation":
		return to.Role == "presentation" || to.Role == "application" || to.Role == "domain"
	case "infrastructure":
		return to.Role == "infrastructure" || to.Role == "application" || to.Role == "domain"
	}
	return false
}

// boundaryFix names a disallowed dependency and the usual correction under R02, R03, and R06.
func boundaryFix(from, to Package) string {
	describe := func(pkg Package) string {
		if pkg.Module == pkg.Role {
			return pkg.Role
		}
		return pkg.Module + " " + pkg.Role
	}
	var fix string
	switch {
	case to.Role == "composition":
		fix = "take the dependency as a parameter that composition supplies"
	case from.Role == "contract":
		fix = "published contracts depend only on other contracts; publish the needed type in a contract package"
	case from.Role == "domain":
		fix = "Domain receives values; move the call to Application and pass the result in"
	case (from.Role == "presentation" && to.Role == "infrastructure") || (from.Role == "infrastructure" && to.Role == "presentation"):
		fix = "keep adapters independent; route through Application"
	case to.Role == "infrastructure" || to.Role == "shared-infrastructure":
		fix = "depend on an interface the consumer defines and wire the adapter in composition"
	case from.Role == "shared-infrastructure":
		fix = "depend on a published or consumer-defined contract instead"
	case to.Role == "presentation":
		fix = "Presentation calls Application, not the reverse"
	case from.Module != to.Module:
		fix = "use the module's published contract or public API"
	default:
		fix = "see the permitted dependencies in R02"
	}
	return describe(from) + " cannot depend on " + describe(to) + "; " + fix
}

// moduleCycle reports one cycle in the declared module graph, if any.
func moduleCycle(graph map[string][]string) string {
	const visiting, done = 1, 2
	state := make(map[string]int)
	var stack []string
	var visit func(string) string
	visit = func(module string) string {
		switch state[module] {
		case visiting:
			start := slices.Index(stack, module)
			return strings.Join(append(slices.Clone(stack[start:]), module), " -> ")
		case done:
			return ""
		}
		state[module] = visiting
		stack = append(stack, module)
		for _, next := range graph[module] {
			if cycle := visit(next); cycle != "" {
				return cycle
			}
		}
		stack = stack[:len(stack)-1]
		state[module] = done
		return ""
	}
	for _, module := range slices.Sorted(maps.Keys(graph)) {
		if cycle := visit(module); cycle != "" {
			return cycle
		}
	}
	return ""
}

// declarationName is part of a violation's baseline identity.
func declarationName(fset *token.FileSet, declaration ast.Decl) string {
	if fn, ok := declaration.(*ast.FuncDecl); ok {
		if fn.Recv != nil && len(fn.Recv.List) > 0 {
			var buffer bytes.Buffer
			_ = format.Node(&buffer, fset, fn.Recv.List[0].Type)
			return buffer.String() + "." + fn.Name.Name
		}
		return fn.Name.Name
	}
	if group, ok := declaration.(*ast.GenDecl); ok {
		var names []string
		for _, spec := range group.Specs {
			switch value := spec.(type) {
			case *ast.TypeSpec:
				names = append(names, value.Name.Name)
			case *ast.ValueSpec:
				for _, name := range value.Names {
					names = append(names, name.Name)
				}
			}
		}
		return strings.Join(names, ",")
	}
	return "declaration"
}
