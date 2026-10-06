package archcheck

import (
	"fmt"
	"go/ast"
	"go/constant"
	"go/types"
	"regexp"
	"slices"
	"strings"

	"golang.org/x/tools/go/types/typeutil"
)

func (c *check) storage(dependency string) bool {
	return slices.ContainsFunc(c.proj.config.StorageImports, func(storage string) bool {
		return dependency == storage || strings.HasPrefix(dependency, storage+"/")
	})
}

// tableOwnership checks the tables named in constant SQL passed to storage packages
// against each module's declared datasets (R09).
func (c *check) tableOwnership(decl ast.Decl, pkg Package, file, symbol string) {
	if len(c.proj.owners) == 0 {
		return
	}
	ast.Inspect(decl, func(node ast.Node) bool {
		call, ok := node.(*ast.CallExpr)
		if !ok {
			return true
		}
		callee := typeutil.Callee(c.pass.TypesInfo, call)
		if callee == nil || callee.Pkg() == nil || !c.storage(callee.Pkg().Path()) {
			return true
		}
		for _, arg := range call.Args {
			value := c.pass.TypesInfo.Types[arg].Value
			if value == nil || value.Kind() != constant.String {
				continue
			}
			for _, table := range sqlTables(constant.StringVal(value)) {
				owner, known := c.proj.owner(table)
				switch {
				case !known:
					c.add(arg.Pos(), "R09", file, symbol, table, fmt.Sprintf("table %s has no declared owner; add it to the owning module's datasets", table))
				case owner != pkg.Module:
					c.add(arg.Pos(), "R09", file, symbol, table, fmt.Sprintf("%s code uses %s's table %s; read or change it through %s's contracts, or record an approved reporting path (R15)", pkg.Module, owner, table, owner))
				}
			}
		}
		return true
	})
}

func (p *project) owner(table string) (string, bool) {
	key := strings.ToLower(table)
	if owner, ok := p.owners[key]; ok {
		return owner, true
	}
	if i := strings.LastIndex(key, "."); i >= 0 {
		owner, ok := p.owners[key[i+1:]]
		return owner, ok
	}
	return "", false
}

var (
	sqlComments = regexp.MustCompile(`(?s)--[^\n]*|/\*.*?\*/`)
	sqlTokens   = regexp.MustCompile("'(?:[^']|'')*'|\"[^\"]*\"|`[^`]*`|\\[[^\\]]*\\]|[A-Za-z_][A-Za-z0-9_$]*(?:\\.[A-Za-z_][A-Za-z0-9_$]*)*|\\S")
	sqlStart    = regexp.MustCompile(`(?i)^\s*(select|insert|update|delete|create|alter|drop|truncate|merge|replace|with)\b`)
	sqlReserved = map[string]bool{"WHERE": true, "JOIN": true, "INNER": true, "LEFT": true, "RIGHT": true, "FULL": true, "OUTER": true,
		"CROSS": true, "NATURAL": true, "ON": true, "USING": true, "GROUP": true, "ORDER": true, "LIMIT": true, "OFFSET": true,
		"UNION": true, "EXCEPT": true, "INTERSECT": true, "HAVING": true, "WINDOW": true, "SET": true, "VALUES": true,
		"RETURNING": true, "FOR": true, "SELECT": true, "SKIP": true, "NOWAIT": true, "OF": true}
	// FROM inside these functions separates arguments rather than naming a table.
	sqlFromFunctions = map[string]bool{"EXTRACT": true, "TRIM": true, "SUBSTRING": true, "SUBSTR": true, "OVERLAY": true, "POSITION": true}
	tableModifiers   = map[string]bool{"IF": true, "NOT": true, "EXISTS": true, "ONLY": true, "LATERAL": true, "TABLE": true}
)

// sqlTables returns the table names a SQL statement reads or writes: those after FROM,
// JOIN, INTO, UPDATE, TABLE, TRUNCATE, and REFERENCES. It excludes common table
// expressions, table-valued function calls, upsert and row-locking clauses, and FROM
// used inside functions such as EXTRACT or in IS DISTINCT FROM.
func sqlTables(sql string) []string {
	sql = sqlComments.ReplaceAllString(sql, " ")
	if !sqlStart.MatchString(sql) {
		return nil
	}
	tokens := sqlTokens.FindAllString(sql, -1)
	identifier := func(i int) bool {
		if i >= len(tokens) {
			return false
		}
		first := tokens[i][0]
		return first == '"' || first == '`' || first == '[' || first == '_' || (first|0x20 >= 'a' && first|0x20 <= 'z')
	}
	name := func(token string) string { return strings.Trim(token, "\"`[]") }
	ctes := make(map[string]bool)
	for i := 1; i+2 < len(tokens); i++ {
		previous := strings.ToUpper(tokens[i-1])
		if (previous == "WITH" || previous == "RECURSIVE" || previous == ",") && strings.EqualFold(tokens[i+1], "AS") && tokens[i+2] == "(" {
			ctes[strings.ToLower(name(tokens[i]))] = true
		}
	}
	// Mark FROM tokens that are function arguments rather than clauses.
	argument := make(map[int]bool)
	var calls []string
	for i, token := range tokens {
		switch {
		case token == "(":
			previous := ""
			if i > 0 {
				previous = strings.ToUpper(tokens[i-1])
			}
			calls = append(calls, previous)
		case token == ")" && len(calls) > 0:
			calls = calls[:len(calls)-1]
		case strings.EqualFold(token, "FROM"):
			inFunction := len(calls) > 0 && sqlFromFunctions[calls[len(calls)-1]]
			distinct := i > 1 && strings.EqualFold(tokens[i-1], "DISTINCT") && (strings.EqualFold(tokens[i-2], "IS") || strings.EqualFold(tokens[i-2], "NOT"))
			argument[i] = inFunction || distinct
		}
	}
	table := func(i int) bool {
		return identifier(i) && !sqlReserved[strings.ToUpper(tokens[i])] && !(i+1 < len(tokens) && tokens[i+1] == "(" && !strings.EqualFold(tokens[i-1], "INTO") && !strings.EqualFold(tokens[i-1], "REFERENCES") && !strings.EqualFold(tokens[i-1], "TABLE") && !tableModifiers[strings.ToUpper(tokens[i-1])])
	}
	var tables []string
	seen := make(map[string]bool)
	add := func(i int) {
		table := name(tokens[i])
		if key := strings.ToLower(table); !ctes[key] && !seen[key] {
			seen[key] = true
			tables = append(tables, table)
		}
	}
	for i, token := range tokens {
		keyword := strings.ToUpper(token)
		switch keyword {
		case "FROM", "JOIN", "INTO", "UPDATE", "TABLE", "TRUNCATE", "REFERENCES":
		default:
			continue
		}
		if argument[i] || (keyword == "UPDATE" && i > 0 && slices.Contains([]string{"FOR", "DO", "KEY"}, strings.ToUpper(tokens[i-1]))) {
			continue
		}
		j := i + 1
		for j < len(tokens) && tableModifiers[strings.ToUpper(tokens[j])] {
			j++
		}
		if !table(j) {
			continue
		}
		add(j)
		if keyword != "FROM" {
			continue
		}
		// FROM a x, b AS y
		for k := j + 1; k < len(tokens); {
			switch {
			case strings.EqualFold(tokens[k], "AS"), identifier(k) && !sqlReserved[strings.ToUpper(tokens[k])]:
				k++
			case tokens[k] == "," && identifier(k+1):
				if table(k + 1) {
					add(k + 1)
				}
				k += 2
			default:
				k = len(tokens)
			}
		}
	}
	return tables
}

// transactionEffects reports goroutines and configured external effects inside callbacks
// passed to the configured transaction functions (R13).
func (c *check) transactionEffects(decl ast.Decl, file, symbol string) {
	config := c.proj.config
	if len(config.TransactionFunctions) == 0 {
		return
	}
	ast.Inspect(decl, func(node ast.Node) bool {
		call, ok := node.(*ast.CallExpr)
		if !ok {
			return true
		}
		transaction := typeutil.Callee(c.pass.TypesInfo, call)
		if transaction == nil || transaction.Pkg() == nil || !slices.Contains(config.TransactionFunctions, qualified(transaction)) {
			return true
		}
		for _, arg := range call.Args {
			callback, ok := arg.(*ast.FuncLit)
			if !ok {
				continue
			}
			ast.Inspect(callback.Body, func(inner ast.Node) bool {
				switch inner := inner.(type) {
				case *ast.GoStmt:
					c.add(inner.Pos(), "R13", file, symbol, "go", fmt.Sprintf("goroutine started inside a %s callback is neither durable nor part of the transaction; record a delivery intent and act after commit", transaction.Name()))
				case *ast.CallExpr:
					if effect := typeutil.Callee(c.pass.TypesInfo, inner); effect != nil && effect.Pkg() != nil && c.externalEffect(effect) {
						c.add(inner.Pos(), "R13", file, symbol, qualified(effect), fmt.Sprintf("external effect inside a %s callback; record a delivery intent in the transaction and perform the effect after commit", transaction.Name()))
					}
				}
				return true
			})
		}
		return true
	})
}

func (c *check) externalEffect(obj types.Object) bool {
	name := qualified(obj)
	return slices.ContainsFunc(c.proj.config.ExternalEffects, func(effect string) bool {
		return name == effect || strings.HasPrefix(name, effect+".")
	})
}

// validQualifiedName accepts import/path.Name and import/path.Type.Method.
func validQualifiedName(name string) bool {
	slash := strings.LastIndex(name, "/")
	dot := strings.Index(name[slash+1:], ".")
	if dot <= 0 {
		return false
	}
	parts := strings.Split(name[slash+2+dot:], ".")
	return len(parts) <= 2 && !slices.Contains(parts, "")
}
