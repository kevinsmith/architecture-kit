// Package golangci registers archcheck as a golangci-lint module plugin.
package golangci

import (
	"github.com/golangci/plugin-module-register/register"
	"golang.org/x/tools/go/analysis"

	"example.com/architecture/check/archcheck"
)

func init() { register.Plugin("archcheck", New) }

type plugin struct{ options archcheck.Options }

// New receives the settings under linters.settings.custom.archcheck.settings.
func New(settings any) (register.LinterPlugin, error) {
	options, err := register.DecodeSettings[archcheck.Options](settings)
	if err != nil {
		return nil, err
	}
	return plugin{options}, nil
}

func (p plugin) BuildAnalyzers() ([]*analysis.Analyzer, error) {
	return []*analysis.Analyzer{archcheck.New(p.options)}, nil
}

func (plugin) GetLoadMode() string { return register.LoadModeTypesInfo }
