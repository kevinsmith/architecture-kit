package archcheck

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPublishedSchemasAndSignatures(t *testing.T) {
	for _, tc := range []struct {
		name, rule string
		files      map[string]string
	}{
		{"raw-message", "R10", map[string]string{"contracts/event.go": "package contracts\nimport \"encoding/json\"\ntype Event struct { Data map[string]json.RawMessage }"}},
		{"local-alias", "R10", map[string]string{"contracts/alias.go": "package contracts\ntype payload = any", "contracts/event.go": "package contracts\ntype Event struct { Data payload }"}},
		{"cross-package-alias", "R10", map[string]string{"ports/alias.go": "package ports\ntype Payload = any", "contracts/event.go": "package contracts\nimport \"fixture.test/app/ports\"\ntype Event struct { Data ports.Payload }"}},
		{"defined-bag", "R10", map[string]string{"contracts/event.go": "package contracts\ntype payload map[string]interface{}; type Event struct { Data payload }"}},
		{"grouped-exported-field", "R10", map[string]string{"contracts/event.go": "package contracts\ntype Event struct { private, Public any }"}},
		{"typed-collection", "", map[string]string{"contracts/event.go": "package contracts\ntype Recipient struct { Address string }; type Event struct { Data map[string][]Recipient }"}},
		{"private-signature", "R05", map[string]string{"access/private/model.go": "package private\ntype Model struct { Name string }", "access/api/api.go": "package api\nimport \"fixture.test/app/access/private\"\nfunc Get() private.Model { return private.Model{} }"}},
		{"hidden-alias-leak", "R05", map[string]string{"access/private/model.go": "package private\ntype Model struct { Name string }", "access/api/alias.go": "package api\nimport \"fixture.test/app/access/private\"\ntype hidden = private.Model", "access/api/api.go": "package api\nfunc Get() hidden { return hidden{} }"}},
		{"inferred-variable-type", "R05", map[string]string{"access/private/model.go": "package private\ntype Model struct { Name string }", "access/api/api.go": "package api\nimport \"fixture.test/app/access/private\"\nvar Default = private.Model{}"}},
		{"public-owned-data", "", map[string]string{"access/api/api.go": "package api\ntype Answer struct { Allowed bool }; func Get() Answer { return Answer{true} }"}},
		{"sql-signature", "R05", map[string]string{"access/api/api.go": "package api\nimport \"database/sql\"\nfunc Get() *sql.Row { return nil }"}},
		{"private-constraint", "R05", map[string]string{"access/private/number.go": "package private\ntype Number interface { ~int | ~float64 }", "access/api/api.go": "package api\nimport \"fixture.test/app/access/private\"\nfunc Sum[T private.Number](values []T) T { var total T; for _, v := range values { total += v }; return total }"}},
		{"inline-constraint", "", map[string]string{"access/api/api.go": "package api\nfunc Sum[T ~int | ~float64](values []T) T { var total T; for _, v := range values { total += v }; return total }"}},
		{"protobuf-any", "R10", map[string]string{
			"go.mod":                      "module fixture.test/app\n\ngo 1.25.0\n\nrequire google.golang.org/protobuf v0.0.0\n\nreplace google.golang.org/protobuf => ./third_party/protobuf\n",
			"third_party/protobuf/go.mod": "module google.golang.org/protobuf\n\ngo 1.25.0\n",
			"third_party/protobuf/types/known/anypb/any.go": "package anypb\ntype Any struct { TypeUrl string; Value []byte }",
			"contracts/event.go":                            "package contracts\nimport \"google.golang.org/protobuf/types/known/anypb\"\ntype Event struct { Payload *anypb.Any }",
		}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			compiles(t, root)
			config := fixtureConfig()
			config.PureImports = append(config.PureImports, "encoding/json", "google.golang.org/protobuf/types/known/anypb")
			config.StorageImports = nil // TestStorageOutsideInfrastructure covers storage access.
			violations, err := analyze(t, root, config, Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.rule == "" && len(violations) != 0 {
				t.Fatalf("valid contract rejected: %+v", violations)
			}
			if tc.rule != "" {
				if len(violations) == 0 {
					t.Fatal("violation escaped")
				}
				for _, v := range violations {
					if v.Rule != tc.rule {
						t.Fatalf("unexpected diagnostic: %+v", v)
					}
				}
			}
		})
	}
}

func TestStalePackageClassificationFails(t *testing.T) {
	root := sourceFixture(t, nil)
	if err := os.RemoveAll(filepath.Join(root, "platform")); err != nil {
		t.Fatal(err)
	}
	if _, err := analyze(t, root, fixtureConfig(), Options{}); err == nil || !strings.Contains(err.Error(), "stale package classification platform") {
		t.Fatalf("stale entry accepted: %v", err)
	}
}

func TestEmptyPackageClassificationFails(t *testing.T) {
	root := sourceFixture(t, nil)
	if err := os.Remove(filepath.Join(root, "platform", "fixture.go")); err != nil {
		t.Fatal(err)
	}
	if _, err := analyze(t, root, fixtureConfig(), Options{}); err == nil || !strings.Contains(err.Error(), "no production Go source") {
		t.Fatalf("empty package accepted: %v", err)
	}
}
