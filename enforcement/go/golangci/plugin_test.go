package golangci

import (
	"testing"

	"github.com/golangci/plugin-module-register/register"
)

func TestPluginBuildsTheConfiguredAnalyzer(t *testing.T) {
	newPlugin, err := register.GetPlugin("archcheck")
	if err != nil {
		t.Fatal(err)
	}
	plugin, err := newPlugin(map[string]any{"config": "architecture.json", "baseline": "baseline.json"})
	if err != nil {
		t.Fatal(err)
	}
	analyzers, err := plugin.BuildAnalyzers()
	if err != nil || len(analyzers) != 1 || analyzers[0].Name != "archcheck" {
		t.Fatalf("analyzers = %v, %v", analyzers, err)
	}
	if mode := plugin.GetLoadMode(); mode != register.LoadModeTypesInfo {
		t.Fatalf("load mode = %q; archcheck needs type information", mode)
	}
}
