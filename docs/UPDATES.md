# Updates and releases

FachDock uses Semantic Versioning for published releases. The application updater supports three update channels: **Stable**, **Release Candidate** and **Develop**. There is intentionally no separate beta channel.

## Channels

### Stable

The default channel. Only non-draft, non-prerelease GitHub Releases with tags in the form `vx.y.z` are considered. This is the production channel.

### Release Candidate

This channel considers both stable releases and release candidates with tags in the form `vx.y.z-rc.n`. Other prerelease identifiers such as alpha or beta are ignored. If a stable release supersedes a release candidate, the stable release is offered automatically.

### Develop

This channel points to the latest `develop` commit whose complete CI quality job succeeded. After successful CI on a push to `develop`, GitHub Actions creates a production-style ZIP plus SHA-256 checksum and publishes them together with a manifest on the rolling `develop-build` branch.

Develop builds do **not** create a Git tag or GitHub Release. The installed build is identified by its full Git commit SHA and stored locally in `storage/develop-build.json`. The Develop channel is intended for testing only.

## Channel configuration

Stable is always available. Release Candidate and Develop are hidden unless they are explicitly enabled in `config/app.local.php`:

```php
<?php

return [
    'updates' => [
        'default_channel' => 'stable',
        'allow_rc' => true,
        'allow_develop' => true,
    ],
];
```

`default_channel` accepts `stable`, `rc` or `develop`. If the configured default channel is not enabled, FachDock safely falls back to Stable.

For a production installation, the recommended configuration is the default:

```php
'updates' => [
    'default_channel' => 'stable',
    'allow_rc' => false,
    'allow_develop' => false,
],
```

## Release flow

1. Features are integrated into `develop`.
2. A `release/x.y.z` branch is stabilized and merged into `main` and back into `develop`.
3. The release commit on `main` is tagged `vx.y.z` or, for an explicitly published release candidate, `vx.y.z-rc.n`.
4. GitHub Actions validates the version and release commit.
5. Production Composer dependencies are installed.
6. A release ZIP and a SHA-256 checksum are generated.
7. A GitHub Release is created automatically. Release candidates are marked as GitHub prereleases.

## Application updater

All channels use the same installation engine. FachDock verifies the SHA-256 checksum, validates the ZIP structure, creates a full backup, enters maintenance mode, deploys the files, runs pending database migrations and records the update in the audit log. If the update fails, the existing rollback mechanism is used.

Updates are never installed silently. An administrator must explicitly select and confirm an available target and enter the current administrator password.

Automatic downgrades are not supported. Switching from a Develop build back to an older or semantically equal published release is therefore intentionally blocked. A later release with a newer Semantic Version can replace a Develop build normally.

Database schema changes are performed through the versioned migration runner. The CLI command `bin/fachdock migrate` remains available for maintenance and update workflows.
