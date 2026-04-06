# Changelog

## v0.6.0 - 2026-04-06

- Hardened the isolation model so every app and sandbox uses its own local `wp-content` as the canonical file and code tree.
- Moved git-backed theme and plugin worktrees fully inside each environment's local `wp-content` instead of relying on shared host paths.
- Updated deploy and cleanup flows to preserve environment-local worktrees, keep `.git` metadata intact, and clean merged branches through worktree-aware git control paths.
- Expanded unit, integration, security, and GitHub E2E coverage around the new environment-local runtime contract.
- Rewrote the docs to describe Rudel as multisite-native site isolation plus environment-local content isolation, with `wp_users` remaining the only intentional shared runtime surface.

## v0.5.9 - 2026-04-06

- Published the verified fix for non-default-port host-site resolution so the clean release includes the bootstrap host-normalization correction and the stabilized `wp-env` HTTP contract coverage.

## v0.5.8 - 2026-04-06

- Hardened the live `wp-env` host-site HTTP contract with a bounded readiness retry so fresh GitHub Actions multisite setups stop failing on startup timing instead of real routing regressions.

## v0.5.7 - 2026-04-06

- Fixed the bootstrap formatting regression so the non-default-port host-site fix ships in a fully green release.

## v0.5.6 - 2026-04-06

- Fixed host-site requests on non-default ports so bootstrap normalizes multisite lookup without dropping the portful canonical main-site URL.
- Added regression coverage for the no-environment host request path that previously broke the live `wp-env` HTTP contract.

## v0.5.5 - 2026-04-06

- Fixed canonical app-domain runtime behavior so app URLs, clone/deploy rewrites, and multisite site records stay aligned to the configured primary domain.
- Fixed multisite bootstrap precedence and local-port request handling so explicit sandbox selection wins and subdomain sites resolve correctly on non-default ports.
- Hardened real database write failure handling to match repository expectations, removed the leftover legacy app-domain map stub, and extended unit/integration/E2E coverage for the new runtime contract.

## v0.5.4 - 2026-04-06

- Published the local-port multisite site-resolution fixes from `main` as the current Composer/GitHub release so downstream consumers resolve the corrected subsite domain handling.

## v0.5.3 - 2026-04-06

- Fixed multisite subsite creation on local networks that run on a custom port by persisting the network port in subsite domain records instead of only appending it to generated URLs.
- Fixed canonical environment URL rendering so existing multisite site records that already carry a port do not get a duplicate `:port` suffix.
- Added focused unit coverage for local-port multisite subsite targets and URL rendering without double-appended ports.
- Extended the live `wp-env` and GitHub E2E suites to prove generated multisite sites resolve over real HTTP for `wp-login.php` and `wp-admin/`.
- Hardened the `wp-env` E2E bootstrap so failed starts clear stale cached project state before retrying.

## v0.5.2 - 2026-04-06

- Fixed multisite subsite cloning so Rudel replaces the fresh site tables WordPress initializes before copying source site state into the new environment.
- Fixed canonical environment URL generation so subdomain sites use the multisite site record plus the network port instead of inheriting stale `siteurl` values during creation and cloning.
- Added focused unit coverage for replacing initialized subsite tables, canonical local-port URL generation, and subsite URL derivation.
- Hardened the `wp-env` E2E harness with retry logic so transient upstream fetch timeouts do not fail the Rudel suite.
- Tightened the multisite docs language so the current runtime model is described directly and consistently across the README and docs pages.

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
