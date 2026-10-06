# Go architecture checker

`archcheck` is a `go/analysis` analyzer for the kit's structural rules. `archcheck/` holds the analyzer and its fixture tests, `cmd/archcheck` the standalone command, and `golangci/` the golangci-lint module plugin.

Installation, configuration, baseline format, and coverage limits are defined in the copyable [Go enforcement guide](../../kit/docs/architecture/go-enforcement.md). Language decisions are in the [Go profile](../../kit/docs/architecture/profiles/go.md). [`architecture.template.json`](architecture.template.json) is the starting configuration for adopting projects, with reviewed pure-role defaults. `.custom-gcl.yml` builds golangci-lint with the plugin, and [`golangci.example.yml`](golangci.example.yml) is the settings block projects merge into their own golangci-lint configuration.

For repository development, use Go 1.27.1. From this directory:

```sh
go test -race -count=1 ./...
go build -o /tmp/archcheck ./cmd/archcheck && (cd ../../examples/go && /tmp/archcheck ./...)
```

Or run `python3 scripts/verify.py` from the repository root to include documentation, the [SQLite scenario](../../examples/go/fixture.md), and installation verification of both hosts. Fixture tests demonstrate permitted and forbidden code; they do not prove every core guarantee.
