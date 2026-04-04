# Rudel

WordPress isolation layer for disposable sandboxes and permanent domain-routed apps. Environments can run on isolated MySQL, SQLite, or multisite sub-sites, with isolated `wp-content`, URLs, and WP-CLI scope.

## Quick Reference

```bash
# Coding standards
composer cs
composer cs:fix
composer stan

# PHPUnit only
composer test
composer test:unit
composer test:integration
composer test:security

# Shell E2E and docs
bash tests/e2e/run-all.sh
bash tests/run-all.sh
npm --prefix docs run build
```

## Workflow

- Default validation path is GitHub Actions. Push changes and watch the `CI` and `Distribution` workflows unless a task explicitly asks for local verification.
- `CI` enforces coding standards, PHPStan, PHPUnit, docs build, and E2E.
- `Distribution` verifies the packaged plugin through zip integrity, Packagist and Composer installs, and WordPress activation.

## Project Structure

```text
rudel/
├── rudel.php                 # Plugin bootstrap, runtime hooks, WP-CLI registration
├── bootstrap.php             # Self-contained pre-boot environment resolver
├── cli/                      # Split WP-CLI surface
│   ├── RudelCommand.php      # create/list/info/destroy/status
│   ├── AppCommand.php        # app create/list/info/destroy/domain-*
│   ├── PromoteCommand.php    # promote sandbox to host
│   ├── PushCommand.php       # push sandbox changes to GitHub
│   ├── PrCommand.php         # open pull requests
│   ├── LogsCommand.php       # inspect environment logs
│   ├── CleanupCommand.php
│   ├── ExportCommand.php
│   ├── ImportCommand.php
│   ├── RestoreCommand.php
│   ├── SnapshotCommand.php
│   ├── TemplateCommand.php
│   └── AbstractEnvironmentCommand.php
├── src/
│   ├── EnvironmentManager.php        # Main sandbox lifecycle orchestrator
│   ├── AppManager.php                # App lifecycle and domain map management
│   ├── BlankWordPressProvisioner.php # Blank environment provisioning
│   ├── ContentCloner.php
│   ├── DatabaseCloner.php
│   ├── MySQLCloner.php
│   ├── SerializedSearchReplace.php
│   ├── SnapshotManager.php
│   ├── TemplateManager.php
│   ├── GitIntegration.php
│   ├── GitHubIntegration.php
│   ├── Environment.php
│   ├── CliCommandMap.php          # Serializable CLI-to-PHP command catalog for harnesses
│   ├── CliCommandAdapters.php     # CLI argument normalization into execution plans
│   ├── RuntimeTableConfig.php     # Advanced runtime-table naming overrides for embedded installs
│   ├── Rudel.php
│   ├── RudelConfig.php
│   └── SubsiteCloner.php
├── docs/                     # Product docs site
├── lib/                      # Bundled SQLite integration, treated as external code
├── templates/                # Generated file templates
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

## Comment Policy

- Use PHPDoc, not JSDoc terminology.
- Inline comments explain why, not what. Keep them for isolation boundaries, ordering requirements, compatibility edges, persistence quirks, or failure modes.
- Public APIs and real contracts get PHPDoc when they need to explain behavior, caveats, or usage.
- PHPCS-required doc comments should stay terse. Avoid filler like `Constructor.` or `Get the X.` when the signature already says that.
- Private and internal methods only need longer PHPDoc when the contract is non-obvious.
- Typed properties may still need short doc comments for WPCS. Keep them low-noise.
- Every `phpcs:ignore` or `phpcs:disable` needs an explicit reason.
- Tests should comment only non-obvious setup, environment gotchas, or regression context.
- No banner comments or decorative separators.
- No em dashes in code, docs, or copy.

## Configuration

Define these in `wp-config.php` before Rudel bootstraps. Path and directory constants must be available before `bootstrap.php` runs.

| Constant | Default | Description |
|----------|---------|-------------|
| `RUDEL_CLI_COMMAND` | `rudel` | Root WP-CLI command name |
| `RUDEL_PATH_PREFIX` | `__rudel` | Path prefix for sandbox URLs |
| `RUDEL_RUNTIME_TABLE_PREFIX` | `rudel_` | Shared runtime-table prefix after the WordPress DB prefix for advanced embedded installs |
| `RUDEL_RUNTIME_TABLE_ENVIRONMENTS` | `rudel_environments` | Explicit environments-table base name override |
| `RUDEL_RUNTIME_TABLE_APPS` | `rudel_apps` | Explicit apps-table base name override |
| `RUDEL_RUNTIME_TABLE_APP_DOMAINS` | `rudel_app_domains` | Explicit app-domains-table base name override |
| `RUDEL_RUNTIME_TABLE_WORKTREES` | `rudel_worktrees` | Explicit worktrees-table base name override |
| `RUDEL_RUNTIME_TABLE_APP_DEPLOYMENTS` | `rudel_app_deployments` | Explicit app-deployments-table base name override |
| `RUDEL_ENVIRONMENTS_DIR` | `WP_CONTENT_DIR . '/rudel-environments'` | Base directory for sandbox environments |
| `RUDEL_APPS_DIR` | `WP_CONTENT_DIR . '/rudel-apps'` | Base directory for apps |
| `RUDEL_GITHUB_TOKEN` | unset | Token for GitHub API-backed push and PR flows |

The runtime-table constants are for advanced embedding and theme-style installs only. They change only the Rudel portion after `$wpdb->base_prefix`, and explicit per-table constants win over the shared `RUDEL_RUNTIME_TABLE_PREFIX`. Define them before Rudel installs or bootstraps persisted runtime tables.

## Key Rules

1. 100% WordPress Coding Standards. If a PHPCS ignore is necessary, the reason must be explicit and defensible.
2. CI is the source of truth for repo work. Prefer push-and-watch over local suite runs unless the task explicitly asks for local execution.
3. `bootstrap.php` is self-contained. No autoloader, no WordPress functions, plain PHP only.
4. Runtime state is DB-backed only. Environment directories hold files and isolated content, but apps, environments, domains, worktrees, deployment records, and Rudel config live in the host WordPress database and must be read and written through repositories/services or WordPress-native DB APIs.
5. Never modify `lib/`. Treat it as bundled external code.
6. When behavior changes, keep CLI help, docs, and tests aligned with the shipped command surface.
7. Keep `CliCommandMap` and `CliCommandAdapters` aligned with the real CLI surface. New or changed commands need a stable operation ID, a public API target, and resolver coverage.
8. Keep PHPStan green for `src/` and `cli/`; if inference is too weak at a dynamic boundary, tighten the contract or isolate the boundary instead of muting the error.

## Harness Contract

- `Rudel::cli_command_map()` exposes the serializable command catalog another harness can inspect.
- `Rudel::resolve_cli_command( $path, $args, $assoc_args )` turns parsed CLI input into an execution plan.
- Execution plans must prefer public PHP callables. Only shell-only behavior, such as `tail -f`, should resolve to a shell transport.
- Adapters own CLI-only normalization like default names, `--clone-all`, `--dry-run`, or confirmation requirements so the same behavior is reusable outside WP-CLI.

## WP-CLI Surface

| Command group | Scope |
|---------------|-------|
| `wp rudel create ...` | Create sandboxes from blank templates, host clones, existing sandboxes, or GitHub repos |
| `wp rudel list`, `info`, `destroy`, `status` | Core sandbox lifecycle and inspection |
| `wp rudel snapshot`, `restore`, `template` | Snapshot and template lifecycle |
| `wp rudel export`, `import`, `cleanup` | Archive and cleanup flows |
| `wp rudel logs`, `promote`, `push`, `pr` | Logs, host promotion, and GitHub publishing |
| `wp rudel app create`, `list`, `info`, `destroy`, `domain-add`, `domain-remove` | Permanent app lifecycle and domain mapping |
