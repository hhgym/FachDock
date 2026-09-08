# Updates and releases

FachDock uses Semantic Versioning and only stable GitHub Releases are considered application updates.

## Release flow

1. Features are integrated into `develop`.
2. A `release/x.y.z` branch is stabilized and merged into `main` and back into `develop`.
3. The release commit on `main` is tagged `vx.y.z`.
4. GitHub Actions validates that the tag is stable SemVer and belongs to `main`.
5. Production Composer dependencies are installed.
6. A release ZIP and a SHA-256 checksum are generated.
7. A GitHub Release is created automatically.

## Application updater

The application-side updater will query the latest stable GitHub Release, compare it with the installed version and offer installation to administrators. Updates are never installed silently.

Database schema changes are performed through the versioned migration runner. The CLI command `bin/fachdock migrate` is available for maintenance and update workflows.

Pre-releases and beta channels are intentionally not supported by the application updater.
