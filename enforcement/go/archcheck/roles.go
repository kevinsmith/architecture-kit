package archcheck

import (
	"fmt"
	"go/ast"
	"go/token"
	"go/types"
	"slices"
)

// classified returns the configuration entry for a package of this Go module.
func (c *check) classified(pkg *types.Package) (Package, bool) {
	if pkg == nil {
		return Package{}, false
	}
	local, ok := c.proj.relative(pkg.Path())
	if !ok {
		return Package{}, false
	}
	entry, ok := c.proj.packages[local]
	return entry, ok
}

// named returns the defined type behind aliases and one pointer, if any.
func named(t types.Type) *types.Named {
	t = types.Unalias(t)
	if pointer, ok := t.(*types.Pointer); ok {
		t = types.Unalias(pointer.Elem())
	}
	n, _ := t.(*types.Named)
	return n
}

func qualified(obj types.Object) string {
	name := obj.Name()
	if fn, ok := obj.(*types.Func); ok {
		if recv := fn.Signature().Recv(); recv != nil {
			if n := named(recv.Type()); n != nil {
				name = n.Obj().Name() + "." + name
			}
		}
	}
	return obj.Pkg().Path() + "." + name
}

// adapterReference applies R02's surface limits to adapters' use of their own module.
// Infrastructure implements contracts and carries values, so it may not call its
// Application's use cases. Presentation uses only boundary values, so it may not call
// Domain functions or use Domain types its Application does not expose. Methods and
// fields of values, and Domain errors, are part of what adapters receive.
func (c *check) adapterReference(id *ast.Ident, obj types.Object, pkg Package, file, symbol string) {
	if obj.Pkg() == c.pass.Pkg {
		return // colocated roles are governed by file overrides
	}
	target, ok := c.classified(obj.Pkg())
	if !ok || target.Module != pkg.Module {
		return
	}
	fn, isFunc := obj.(*types.Func)
	var recv *types.Var
	if isFunc {
		recv = fn.Signature().Recv()
		if recv != nil && types.IsInterface(recv.Type()) {
			return // an interface call reaches whatever composition supplies
		}
	}
	add := func(message string) { c.add(id.Pos(), "R02", file, symbol, qualified(obj), message) }
	switch {
	case pkg.Role == "infrastructure" && isFunc && target.Role == "application":
		add("Infrastructure calls its module's Application code; adapters implement contracts and carry values, so have Application make the call")
	case pkg.Role == "presentation" && target.Role == "domain" && isFunc && recv == nil:
		add("Presentation calls a Domain function; have Application make the decision and return the result")
	case pkg.Role == "presentation" && target.Role == "domain" && !isFunc && !boundaryValue(obj, c.boundary(pkg.Module)):
		add("Presentation uses a Domain value outside its Application's boundary signatures; return it from an Application boundary instead")
	}
}

func boundaryValue(obj types.Object, boundary map[*types.TypeName]bool) bool {
	switch obj := obj.(type) {
	case *types.TypeName:
		return boundary[obj] || isError(obj.Type())
	case *types.Var:
		if obj.IsField() || isError(obj.Type()) {
			return true
		}
		if n := named(obj.Type()); n != nil {
			return boundary[n.Obj()]
		}
	case *types.Const:
		if n := named(obj.Type()); n != nil {
			return boundary[n.Obj()]
		}
	}
	return false
}

// isError reports error values and error types, which are outcomes under R12.
func isError(t types.Type) bool {
	errorType := types.Universe.Lookup("error").Type().Underlying().(*types.Interface)
	return types.Implements(t, errorType) || types.Implements(types.NewPointer(t), errorType)
}

// boundary collects the Domain types reachable from the exported surface of the
// module's Application packages that this package imports, including through the
// exported fields and methods of the types it reaches.
func (c *check) boundary(module string) map[*types.TypeName]bool {
	if c.boundarySet != nil {
		return c.boundarySet
	}
	set, seen := make(map[*types.TypeName]bool), make(map[*types.TypeName]bool)
	var visit func(types.Type)
	visit = func(t types.Type) {
		switch t := t.(type) {
		case *types.Alias:
			visit(types.Unalias(t))
		case *types.Named:
			obj := t.Obj()
			if seen[obj] {
				return
			}
			seen[obj] = true
			entry, ok := c.classified(obj.Pkg())
			if !ok || entry.Module != module || (entry.Role != "domain" && entry.Role != "application") {
				return
			}
			if entry.Role == "domain" {
				set[obj] = true
			}
			for argument := range t.TypeArgs().Types() {
				visit(argument)
			}
			visit(t.Underlying())
			for method := range t.Methods() {
				if method.Exported() {
					visit(method.Type())
				}
			}
		case *types.Pointer:
			visit(t.Elem())
		case *types.Slice:
			visit(t.Elem())
		case *types.Array:
			visit(t.Elem())
		case *types.Chan:
			visit(t.Elem())
		case *types.Map:
			visit(t.Key())
			visit(t.Elem())
		case *types.Signature:
			visit(t.Params())
			visit(t.Results())
		case *types.Tuple:
			for v := range t.Variables() {
				visit(v.Type())
			}
		case *types.Struct:
			for field := range t.Fields() {
				if field.Exported() || field.Embedded() {
					visit(field.Type())
				}
			}
		case *types.Interface:
			for method := range t.Methods() {
				visit(method.Type())
			}
		}
	}
	for _, imported := range c.pass.Pkg.Imports() {
		entry, ok := c.classified(imported)
		if !ok || entry.Role != "application" || entry.Module != module {
			continue
		}
		scope := imported.Scope()
		for _, name := range scope.Names() {
			obj := scope.Lookup(name)
			if !obj.Exported() {
				continue
			}
			visit(obj.Type())
		}
	}
	c.boundarySet = set
	return set
}

// domainWrites reports Domain code that writes package-level variables (R04).
func (c *check) domainWrites(decl ast.Decl, file, symbol string) {
	ast.Inspect(decl, func(node ast.Node) bool {
		var targets []ast.Expr
		switch node := node.(type) {
		case *ast.AssignStmt:
			if node.Tok != token.DEFINE {
				targets = node.Lhs
			}
		case *ast.IncDecStmt:
			targets = []ast.Expr{node.X}
		case *ast.UnaryExpr:
			if node.Op == token.AND {
				targets = []ast.Expr{node.X}
			}
		}
		for _, target := range targets {
			if v := c.packageVariable(target); v != nil {
				c.add(target.Pos(), "R04", file, symbol, v.Pkg().Path()+"."+v.Name(), "Domain writes package-level state; keep state in values that are passed in and returned")
			}
		}
		return true
	})
}

// packageVariable returns the package-level variable that a write target reaches, if any.
func (c *check) packageVariable(e ast.Expr) *types.Var {
	for {
		switch x := e.(type) {
		case *ast.ParenExpr:
			e = x.X
		case *ast.IndexExpr:
			e = x.X
		case *ast.StarExpr:
			e = x.X
		case *ast.SelectorExpr:
			if id, ok := x.X.(*ast.Ident); ok {
				if _, isPackage := c.pass.TypesInfo.Uses[id].(*types.PkgName); isPackage {
					e = x.Sel
					continue
				}
			}
			e = x.X
		case *ast.Ident:
			if v, ok := c.pass.TypesInfo.Uses[x].(*types.Var); ok && !v.IsField() && v.Pkg() != nil && v.Parent() == v.Pkg().Scope() {
				return v
			}
			return nil
		default:
			return nil
		}
	}
}

// wiring reports composition that injects one module's implementation into another
// module's interface without the declared module dependency (R03).
func (c *check) wiring(decl ast.Decl, file, symbol string) {
	inject := func(expr ast.Expr, target types.Type) { c.injected(expr, target, file, symbol) }
	ast.Inspect(decl, func(node ast.Node) bool {
		switch node := node.(type) {
		case *ast.CallExpr:
			callee := c.pass.TypesInfo.Types[node.Fun]
			if callee.IsType() {
				if len(node.Args) == 1 {
					inject(node.Args[0], callee.Type)
				}
				return true
			}
			sig, ok := callee.Type.Underlying().(*types.Signature)
			if !ok {
				return true
			}
			params := sig.Params()
			for i, arg := range node.Args {
				switch {
				case sig.Variadic() && i >= params.Len()-1:
					last := params.At(params.Len() - 1).Type()
					if slice, ok := last.Underlying().(*types.Slice); ok && !node.Ellipsis.IsValid() {
						inject(arg, slice.Elem())
					} else {
						inject(arg, last)
					}
				case i < params.Len():
					inject(arg, params.At(i).Type())
				}
			}
		case *ast.CompositeLit:
			t := c.pass.TypesInfo.TypeOf(node)
			if t == nil {
				return true
			}
			for i, element := range node.Elts {
				value := element
				if kv, ok := element.(*ast.KeyValueExpr); ok {
					value = kv.Value
				}
				switch u := t.Underlying().(type) {
				case *types.Struct:
					if kv, ok := element.(*ast.KeyValueExpr); ok {
						if key, ok := kv.Key.(*ast.Ident); ok {
							if field, ok := c.pass.TypesInfo.Uses[key].(*types.Var); ok {
								inject(value, field.Type())
							}
						}
					} else if i < u.NumFields() {
						inject(value, u.Field(i).Type())
					}
				case *types.Slice:
					inject(value, u.Elem())
				case *types.Array:
					inject(value, u.Elem())
				case *types.Map:
					inject(value, u.Elem())
				}
			}
		case *ast.AssignStmt:
			if node.Tok == token.ASSIGN && len(node.Lhs) == len(node.Rhs) {
				for i := range node.Lhs {
					inject(node.Rhs[i], c.pass.TypesInfo.TypeOf(node.Lhs[i]))
				}
			}
		case *ast.ValueSpec:
			if node.Type != nil {
				for _, value := range node.Values {
					inject(value, c.pass.TypesInfo.TypeOf(node.Type))
				}
			}
		}
		return true
	})
}

func (c *check) injected(expr ast.Expr, target types.Type, file, symbol string) {
	if target == nil {
		return
	}
	port, ok := types.Unalias(target).(*types.Named)
	if !ok || !types.IsInterface(port) {
		return
	}
	source := c.pass.TypesInfo.TypeOf(expr)
	if source == nil || types.IsInterface(source) {
		return
	}
	provider := named(source)
	if provider == nil {
		return
	}
	consumer, ok := c.classified(port.Obj().Pkg())
	if !ok {
		return
	}
	supplier, ok := c.classified(provider.Obj().Pkg())
	if !ok || consumer.Module == supplier.Module {
		return
	}
	// Published ports and wiring-only packages define no module edge, and Shared
	// Infrastructure depends only on contracts, so it cannot close a cycle.
	if slices.Contains([]string{"contract", "composition", "test"}, consumer.Role) || slices.Contains([]string{"shared-infrastructure", "composition", "test"}, supplier.Role) {
		return
	}
	from, to := consumer.Module, supplier.Module
	message := fmt.Sprintf("composition wires %s's %s into %s's %s, so %s depends on %s; declare it in depends_on or wire a different provider",
		to, provider.Obj().Name(), from, port.Obj().Name(), from, to)
	if c.subscription(port, consumer.Module) {
		from, to = to, from
		message = fmt.Sprintf("%s subscribes to %s's events through %s, so %s depends on %s; declare it in depends_on",
			from, to, port.Obj().Name(), from, to)
	}
	if !slices.Contains(c.proj.config.DependsOn[from], to) {
		c.add(expr.Pos(), "R03", file, symbol, qualified(provider.Obj())+" as "+qualified(port.Obj()), message)
	}
}

// subscription reports a delivery port: every method takes only the module's own published
// contract values (and a context) and returns at most an error. Its implementations
// subscribe to the publisher rather than serve it.
func (c *check) subscription(port *types.Named, module string) bool {
	iface := port.Underlying().(*types.Interface)
	if iface.NumMethods() == 0 {
		return false
	}
	errorType := types.Universe.Lookup("error").Type()
	for method := range iface.Methods() {
		sig := method.Signature()
		results := sig.Results()
		if results.Len() > 1 || (results.Len() == 1 && !types.Identical(results.At(0).Type(), errorType)) {
			return false
		}
		events := 0
		for param := range sig.Params().Variables() {
			n := named(param.Type())
			if n == nil || n.Obj().Pkg() == nil {
				return false
			}
			if n.Obj().Pkg().Path() == "context" && n.Obj().Name() == "Context" {
				continue
			}
			entry, ok := c.classified(n.Obj().Pkg())
			if !ok || entry.Role != "contract" || entry.Module != module {
				return false
			}
			events++
		}
		if events == 0 {
			return false
		}
	}
	return true
}
