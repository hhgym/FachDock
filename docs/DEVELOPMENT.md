# Development

FachDock follows Git Flow.

## Branches

- `main`: stable releases only
- `develop`: integration branch for the next release
- `feature/*`: feature development based on `develop`
- `release/*`: release stabilization
- `hotfix/*`: urgent fixes based on `main`

## Versioning

Semantic Versioning is used. During initial development the project uses `0.x.y`; `1.0.0` will be the first production-ready major release.

## Quality gates

Before merging feature work into `develop`, the project is intended to pass:

- PHP syntax checks
- PHPUnit
- PHPStan
- PSR-12/code-style validation

CI is expanded incrementally as the corresponding tooling is added.
