# Development

FachDock follows Git Flow with `develop` as the integration branch.

## Branches

- `main`: stable releases only
- `develop`: integration branch for the next release
- `feature/*`: feature development based on `develop`
- `release/*`: release stabilization
- `hotfix/*`: urgent fixes based on `main`

## Versioning

Semantic Versioning is used. During initial development the project uses `0.x.y`; `1.0.0` will be the first production-ready major release.

## Local quality checks

Install development dependencies with Composer and run:

```bash
composer check
```

This executes:

- PHPUnit
- PHPStan
- PHP-CS-Fixer in dry-run mode

PHP syntax is additionally checked in GitHub Actions.

## Pull requests

Feature branches are merged into `develop` through pull requests after successful CI checks. Release branches are stabilized before merging into `main` and `develop`.
