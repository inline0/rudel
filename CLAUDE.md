# Rudel

Request-selected WordPress overlay environments for sandboxes and apps.

## Quick Reference

```bash
composer cs
composer cs:fix
composer stan

composer test
composer test:unit
composer test:integration
composer test:security

bash tests/e2e/run-all.sh
bash tests/run-all.sh
npm --prefix docs run build
```

## Current Architecture

- Rudel runs on a normal WordPress installation. Multisite is not required.
- Every Rudel sandbox and app is an overlay environment selected per request.
- Runtime selection uses `X-Rudel-Environment`, `rudel_environment`, `RUDEL_ENVIRONMENT` for CLI, or mapped app domains.
- Environment site data uses cloned WordPress tables with generated prefixes such as `wp_a4nmv7_`.
- Environment code isolation is currently active-theme isolation. The selected theme is copied into the environment directory and loaded through theme filters.
- Host WordPress core, plugins, uploads, and users are shared by default.
- Runtime metadata lives in host WordPress MySQL tables, never JSON and never SQLite.

## Project Structure

```text
rudel/
├── rudel.php                 # Plugin bootstrap, runtime hooks, WP-CLI registration
├── bootstrap.php             # Early request overlay resolver loaded from wp-config.php
├── cli/                      # Split WP-CLI surface
├── src/                      # Runtime models, repositories, managers, services
├── templates/                # Legacy/runtime templates where still needed
├── docs/                     # Product docs site
├── tests/                    # Unit, integration, security, and E2E tests
└── .github/workflows/
```

## Configuration

Define these before Rudel boots when non-default paths or names are needed:

| Constant | Default | Description |
|----------|---------|-------------|
| `RUDEL_CLI_COMMAND` | `rudel` | Root WP-CLI command name |
| `RUDEL_RUNTIME_TABLE_PREFIX` | `rudel_` | Shared runtime-table prefix after the WordPress DB prefix |
| `RUDEL_RUNTIME_TABLE_ENVIRONMENTS` | `rudel_environments` | Explicit environments-table base name override |
| `RUDEL_RUNTIME_TABLE_APPS` | `rudel_apps` | Explicit apps-table base name override |
| `RUDEL_RUNTIME_TABLE_APP_DOMAINS` | `rudel_app_domains` | Explicit app-domains-table base name override |
| `RUDEL_RUNTIME_TABLE_WORKTREES` | `rudel_worktrees` | Explicit worktrees-table base name override |
| `RUDEL_RUNTIME_TABLE_APP_DEPLOYMENTS` | `rudel_app_deployments` | Explicit app-deployments-table base name override |
| `RUDEL_ENVIRONMENTS_DIR` | `WP_CONTENT_DIR . '/rudel-environments'` | Base directory for sandbox environments |
| `RUDEL_APPS_DIR` | `WP_CONTENT_DIR . '/rudel-apps'` | Base directory for app environments |

## Key Rules

1. Rudel is overlay-first. Do not reintroduce multisite as a runtime requirement.
2. CI is the source of truth for repo work. Keep coding standards, static analysis, PHPUnit, docs build, and E2E green.
3. `bootstrap.php` stays self-contained: no autoloader, no WordPress functions, plain PHP only.
4. Runtime state is DB-backed only. Apps, environments, domains, worktrees, deployments, and config belong in WordPress tables.
5. Environment table prefixes must be unique and must not overwrite the host WordPress prefix itself.
6. Selected requests must not redefine `WP_HOME`, `WP_SITEURL`, or `WP_CONTENT_DIR` globally.
7. Theme isolation is the current filesystem isolation boundary. Plugins, uploads, and users remain shared unless a future version explicitly changes that model.
8. Keep CLI help, docs, tests, and `CliCommandMap` aligned with the shipped command surface.
9. Prefer positive assertions of the current contract over legacy-removal assertions in tests.
10. Keep Rudel product-neutral. Do not mention downstream product names in docs, comments, examples, changelogs, or release notes.
11. `CHANGELOG.md` is the source of truth for release notes. GitHub release bodies must be exactly `See CHANGELOG.md for the full release notes.` with no compare links or duplicated summaries.

## Comment Policy

- Public APIs and real contracts get PHPDoc when it helps callers.
- Private/internal methods only get PHPDoc when the contract is non-obvious.
- Inline comments explain why, not what.
- Every `phpcs:ignore` and `phpcs:disable` must include a reason.
- Avoid filler docblocks such as `Constructor.` or `Get the X.`
- If WPCS/PHPCS requires doc comments, keep them terse and low-noise.
