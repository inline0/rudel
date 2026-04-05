# Changelog

## v0.5.0 - 2026-04-05

- Simplified Rudel to a single runtime model built on subdomain multisite sites.
- Removed prefixed preview routing, preview cookies, SQLite-specific paths, and the export/import/promote command surface.
- Kept apps and sandboxes as first-class Rudel records backed by real multisite sites, snapshots, backups, templates, worktrees, and deployment history.
- Reworked the live `wp-env` suite to prove the current contract end to end: native multisite URLs, snapshot restore, app backups, app-derived sandboxes, and app deployment/restore flows.
- Updated the CLI, docs, runtime bootstrap, and generated environment files to describe the current multisite-only architecture.
