# Rudel

WordPress isolation layer for disposable sandboxes and permanent domain-routed apps. Environments can run on isolated MySQL, SQLite, or multisite sub-sites, with isolated `wp-content`, URLs, and WP-CLI scope.

## Quick Reference

```bash
# Coding standards
composer cs
composer cs:fix
composer phpstan

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

- Production code comments must explain why the code is shaped this way: invariants, ordering requirements, isolation boundaries, compatibility constraints, or failure modes.
- Delete comments that only narrate what the code is already saying.
- Internal helpers usually do not need PHPDoc. Add it only when the contract is not obvious from the signature or when side effects or constraints matter.
- Public entry points should keep concise PHPDoc. That includes externally consumed API methods, WP-CLI commands, and hooks or filters whose contract is not obvious.
- Examples belong in PHPDoc only when they clarify real usage. Do not add boilerplate examples for obvious methods.
- Tests should only comment non-obvious fixture setup, shell or environment gotchas, or regression context.
- No banner comments or decorative separators.
- No em dashes in code, docs, or copy.

## Configuration

Define these in `wp-config.php` before Rudel bootstraps. Path and directory constants must be available before `bootstrap.php` runs.

| Constant | Default | Description |
|----------|---------|-------------|
| `RUDEL_CLI_COMMAND` | `rudel` | Root WP-CLI command name |
| `RUDEL_PATH_PREFIX` | `__rudel` | Path prefix for sandbox URLs |
| `RUDEL_ENVIRONMENTS_DIR` | `WP_CONTENT_DIR . '/rudel-environments'` | Base directory for sandbox environments |
| `RUDEL_APPS_DIR` | `WP_CONTENT_DIR . '/rudel-apps'` | Base directory for apps |
| `RUDEL_GITHUB_TOKEN` | unset | Token for GitHub API-backed push and PR flows |

## Key Rules

1. 100% WordPress Coding Standards. If a PHPCS ignore is necessary, the reason must be explicit and defensible.
2. CI is the source of truth for repo work. Prefer push-and-watch over local suite runs unless the task explicitly asks for local execution.
3. `bootstrap.php` is self-contained. No autoloader, no WordPress functions, plain PHP only.
4. Filesystem state is the source of truth. Environment directories, `.rudel.json`, `domains.json`, and snapshot files matter more than in-memory bookkeeping.
5. Never modify `lib/`. Treat it as bundled external code.
6. When behavior changes, keep CLI help, docs, and tests aligned with the shipped command surface.
7. Keep PHPStan green for `src/` and `cli/`; if inference is too weak at a dynamic boundary, tighten the contract or isolate the boundary instead of muting the error.

## WP-CLI Surface

| Command group | Scope |
|---------------|-------|
| `wp rudel create ...` | Create sandboxes from blank templates, host clones, existing sandboxes, or GitHub repos |
| `wp rudel list`, `info`, `destroy`, `status` | Core sandbox lifecycle and inspection |
| `wp rudel snapshot`, `restore`, `template` | Snapshot and template lifecycle |
| `wp rudel export`, `import`, `cleanup` | Archive and cleanup flows |
| `wp rudel logs`, `promote`, `push`, `pr` | Logs, host promotion, and GitHub publishing |
| `wp rudel app create`, `list`, `info`, `destroy`, `domain-add`, `domain-remove` | Permanent app lifecycle and domain mapping |
