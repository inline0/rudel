# Rudel

WordPress environment orchestration on top of subdomain multisite.

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

- Rudel requires a subdomain multisite network.
- Every Rudel sandbox and app is a real multisite site.
- Browser/runtime identity always flows through the real site URL.
- Runtime metadata lives in WordPress tables, not JSON files.
- Environment directories exist for generated files, isolated content, snapshots, backups, and worktrees.

## Project Structure

```text
rudel/
├── rudel.php                 # Plugin bootstrap, runtime hooks, WP-CLI registration
├── bootstrap.php             # Early runtime bootstrap loaded from wp-config.php
├── cli/                      # Split WP-CLI surface
│   ├── RudelCommand.php
│   ├── AppCommand.php
│   ├── CleanupCommand.php
│   ├── LogsCommand.php
│   ├── PrCommand.php
│   ├── PushCommand.php
│   ├── RestoreCommand.php
│   ├── SnapshotCommand.php
│   ├── TemplateCommand.php
│   └── AbstractEnvironmentCommand.php
├── src/
│   ├── EnvironmentManager.php
│   ├── AppManager.php
│   ├── EnvironmentStateReplacer.php
│   ├── SubsiteCloner.php
│   ├── SnapshotManager.php
│   ├── TemplateManager.php
│   ├── Environment.php
│   ├── CliCommandMap.php
│   ├── CliCommandAdapters.php
│   ├── RuntimeTableConfig.php
│   ├── Rudel.php
│   └── RudelConfig.php
├── templates/                # Generated bootstrap and runtime templates
├── docs/                     # Product docs site
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Security/
│   └── e2e/
└── .github/workflows/
    ├── ci.yml
    ├── dist.yml
    └── release.yml
```

## Configuration

Define these before Rudel boots when you need non-default paths or naming:

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
| `RUDEL_GITHUB_TOKEN` | unset | Token for GitHub API-backed push and PR flows |

## Key Rules

1. Rudel is multisite-only. Do not add path-routed runtime behavior.
2. CI is the source of truth for repo work. Keep coding standards, static analysis, PHPUnit, docs build, and e2e green.
3. `bootstrap.php` stays self-contained. No autoloader, no WordPress functions, plain PHP only.
4. Runtime state is DB-backed only. Apps, environments, domains, worktrees, deployments, and config belong in WordPress tables.
5. Generated environment files should describe the current runtime contract. No transitional language.
6. Keep CLI help, docs, tests, and `CliCommandMap` aligned with the shipped command surface.
7. `tests/e2e/test-wp-env.sh` is the live proof of the multisite lifecycle contract. Keep it current.
8. Prefer positive assertions of the current contract over legacy-removal assertions in tests.
