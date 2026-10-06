package archcheck

import (
	"go/types"
	"slices"
)

// surface returns the first type in a published declaration that leaks a private or
// unapproved type (payload false, R05) or carries an unstructured payload (payload true, R10).
func (c *check) surface(t types.Type, payload bool, seen map[*types.TypeName]bool) string {
	recur := func(t types.Type) string { return c.surface(t, payload, seen) }
	switch t := t.(type) {
	case *types.Alias:
		if obj := t.Obj(); obj.Pkg() != nil {
			dependency := obj.Pkg().Path()
			if payload && opaque(dependency, obj.Name()) {
				return dependency + "." + obj.Name()
			}
			if _, local := c.proj.relative(dependency); !local {
				// An external alias is approved by the package the code imports, not its target's.
				if !payload {
					if dependency != "context" && !slices.Contains(c.proj.config.PureImports, dependency) {
						return dependency + "." + obj.Name()
					}
					return ""
				}
			}
		}
		return recur(types.Unalias(t))
	case *types.Named:
		obj := t.Obj()
		if obj.Pkg() == nil || seen[obj] {
			return ""
		}
		seen[obj] = true
		dependency := obj.Pkg().Path()
		if payload && opaque(dependency, obj.Name()) {
			return dependency + "." + obj.Name()
		}
		for argument := range t.TypeArgs().Types() {
			if bad := recur(argument); bad != "" {
				return bad
			}
		}
		if local, ok := c.proj.relative(dependency); ok {
			target := c.proj.packages[local]
			if !payload && local != c.rel && target.Role != "contract" && !target.Public {
				return dependency + "." + obj.Name()
			}
			if !payload && t.TypeArgs().Len() == 0 {
				if bad := c.constraints(t.TypeParams(), seen); bad != "" {
					return bad
				}
			}
			return recur(t.Underlying())
		}
		if !payload && dependency != "context" && !slices.Contains(c.proj.config.PureImports, dependency) {
			return dependency + "." + obj.Name()
		}
	case *types.Pointer:
		return recur(t.Elem())
	case *types.Slice:
		return recur(t.Elem())
	case *types.Array:
		return recur(t.Elem())
	case *types.Chan:
		return recur(t.Elem())
	case *types.Map:
		if bad := recur(t.Key()); bad != "" {
			return bad
		}
		return recur(t.Elem())
	case *types.Signature:
		if !payload {
			if bad := c.constraints(t.TypeParams(), seen); bad != "" {
				return bad
			}
		}
		if bad := recur(t.Params()); bad != "" {
			return bad
		}
		return recur(t.Results())
	case *types.Tuple:
		for v := range t.Variables() {
			if bad := recur(v.Type()); bad != "" {
				return bad
			}
		}
	case *types.Struct:
		for field := range t.Fields() {
			if field.Exported() || field.Embedded() {
				if bad := recur(field.Type()); bad != "" {
					return bad
				}
			}
		}
	case *types.Interface:
		if !t.IsMethodSet() {
			if payload {
				return "" // a constraint is not a schema
			}
			for embedded := range t.EmbeddedTypes() {
				if bad := recur(embedded); bad != "" {
					return bad
				}
			}
			return ""
		}
		if payload && t.Empty() {
			return "any"
		}
		for method := range t.Methods() {
			if bad := recur(method.Type()); bad != "" {
				return bad
			}
		}
	case *types.Union:
		for term := range t.Terms() {
			if bad := recur(term.Type()); bad != "" {
				return bad
			}
		}
	}
	return ""
}

// constraints inspects the type parameter constraints of a published generic declaration.
func (c *check) constraints(params *types.TypeParamList, seen map[*types.TypeName]bool) string {
	for param := range params.TypeParams() {
		if bad := c.surface(param.Constraint(), false, seen); bad != "" {
			return bad
		}
	}
	return ""
}

// Opaque payload types carry data without a schema. Under GOEXPERIMENT=jsonv2,
// json.RawMessage is an alias of jsontext.Value.
var opaqueTypes = map[string][]string{
	"encoding/json":                                   {"RawMessage"},
	"encoding/json/jsontext":                          {"Value"},
	"google.golang.org/protobuf/types/known/anypb":    {"Any"},
	"google.golang.org/protobuf/types/known/structpb": {"Struct", "Value", "ListValue"},
}

func opaque(dependency, name string) bool { return slices.Contains(opaqueTypes[dependency], name) }
