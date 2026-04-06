# Changelog

## v0.5.1 - 2026-04-06

- Fixed GitHub-backed sandbox metadata updates so clone-source repository state and tracked worktrees persist correctly in runtime tables.
- Hardened the live GitHub workflow coverage to prove repository download, sandbox push, pull request creation, merge cleanup, app tracking, and app-derived sandbox inheritance end to end.
- Added non-GitHub multisite e2e coverage for tracked app GitHub metadata so CI catches inheritance regressions without needing live API behavior.
- Rewrote the multisite docs so the runtime model, isolation story, GitHub flow, and app lifecycle match the current product instead of the removed preview/subpath experiments.

## v0.5.0 - 2026-04-05

- Simplified Rudel to a single runtime model built on subdomain multisite sites.
- Removed prefixed preview routing, preview cookies, SQLite-specific paths, and the export/import/promote command surface.
- Kept apps and sandboxes as first-class Rudel records backed by real multisite sites, snapshots, backups, templates, worktrees, and deployment history.
- Reworked the live `wp-env` suite to prove the current contract end to end: native multisite URLs, snapshot restore, app backups, app-derived sandboxes, and app deployment/restore flows.
- Updated the CLI, docs, runtime bootstrap, and generated environment files to describe the current multisite-only architecture.
