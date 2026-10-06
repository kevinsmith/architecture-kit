// Command archcheck runs the architecture analyzer on its own or as a go vet tool.
package main

import (
	"golang.org/x/tools/go/analysis/singlechecker"

	"example.com/architecture/check/archcheck"
)

func main() { singlechecker.Main(archcheck.Analyzer) }
