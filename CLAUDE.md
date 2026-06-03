# Rudel

Request-selected WordPress overlay environments for sandboxes.

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
- Every Rudel environment is a sandbox overlay selected per request.
- Runtime selection uses the trusted header, cookie, and CLI environment variable declared by the active runtime profile.
- Environment site data uses cloned WordPress tables with generated prefixes such as `wp_a4nmv7_`.
- Environment code isolation is active-theme isolation. The selected theme is copied into the environment directory and loaded through theme filters.
- Host WordPress core, plugins, uploads, and users are shared by default.
- Runtime metadata lives in profile-configured host WordPress MySQL tables, never JSON and never SQLite.

## Project Structure

```text
rudel/
├── rudel.php                 # Plugin bootstrap, runtime hooks, WP-CLI registration
├── bootstrap.php             # Early request overlay resolver loaded from wp-config.php
├── cli/                      # Split WP-CLI surface
├── src/                      # Runtime models, repositories, managers, services
├── templates/                # Runtime templates where still needed
├── docs/                     # Product docs site
├── tests/                    # Unit, integration, security, and E2E tests
└── .github/workflows/
```

## Configuration

Rudel has no built-in public runtime names. The implementer must provide a runtime profile before activation/bootstrap. Profiles can be supplied through the `rudel_runtime_profile` filter for WordPress activation and normal runtime services, or through `$GLOBALS['rudel_runtime_profile']` before `bootstrap.php` runs.

The profile owns:

- selector names for HTTP header, cookie, and CLI environment variable
- runtime constant names emitted by `bootstrap.php`
- runtime metadata table names
- generated table-prefix patterns and git branch prefix
- environments directory name/path override constant
- generated runtime MU-plugin filename, function prefix, admin-bar labels, and email-log label
- wp-config marker and generated profile config path

`RUDEL_CLI_COMMAND` remains a repo-local WP-CLI command constant. It can be defined before plugin load to change the root command name from `rudel`.

## Key Rules

1. Rudel is overlay-first. Do not reintroduce multisite as a runtime requirement.
2. CI is the source of truth for repo work. Keep coding standards, static analysis, PHPUnit, docs build, and E2E green.
3. `bootstrap.php` stays self-contained: no autoloader, no WordPress functions, plain PHP only.
4. Runtime state is DB-backed only. Environment records, worktrees, snapshots, policy metadata, and config belong in WordPress tables.
5. Environment table prefixes must be unique and must not overwrite the host WordPress prefix itself.
6. Selected requests must not redefine `WP_HOME`, `WP_SITEURL`, or `WP_CONTENT_DIR` globally.
7. Theme isolation is the current filesystem isolation boundary. Plugins, uploads, and users remain shared unless a future version explicitly changes that model.
8. Keep CLI help, docs, tests, and `CliCommandMap` aligned with the shipped command surface.
9. Prefer positive assertions of the current contract over legacy-removal assertions in tests.
10. Keep Rudel product-neutral. Do not mention downstream product names in docs, comments, examples, changelogs, or release notes.
11. Do not add default public Rudel runtime selectors, cookies, constants, database table names, generated file names, or path names. Tests may use Rudel-shaped fixture profiles only when the profile is explicit.
12. `CHANGELOG.md` is the source of truth for release notes. GitHub release bodies must be exactly `See CHANGELOG.md for the full release notes.` with no compare links or duplicated summaries.

## Comment Policy

- Public APIs and real contracts get PHPDoc when it helps callers.
- Private/internal methods only get PHPDoc when the contract is non-obvious.
- Inline comments explain why, not what.
- Every `phpcs:ignore` and `phpcs:disable` must include a reason.
- Avoid filler docblocks such as `Constructor.` or `Get the X.`
- If WPCS/PHPCS requires doc comments, keep them terse and low-noise.
