<p align="center">
  <a href="https://rudel.dev">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
      <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
      <img alt="Rudel" src="./.github/logo-light.svg" height="50">
    </picture>
  </a>
</p>

<p align="center">
  The WordPress isolation layer
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin for running fully isolated environments inside an existing WordPress installation. It supports both disposable sandboxes for development and permanent apps routed by domain. Each environment gets its own database isolation (MySQL by default, SQLite, or multisite sub-site where supported), `wp-content` directory, and WP-CLI scope.

**Use cases:**
- Test plugin and theme changes without touching your live site
- Give AI coding agents (Claude Code, Cursor) a safe, scoped WordPress environment
- Spin up disposable environments for demos, QA, or client reviews
- Host multiple isolated client sites from one WordPress install with app mode
- Snapshot and restore at any point

## Quick Start

**Prerequisites:**
- PHP 8.0+
- WordPress 6.4+

```bash
composer require rudel/rudel
wp plugin activate rudel
```

Create your first sandbox:

```bash
wp rudel create --name="my-sandbox"
```

Use it:

```bash
cd /path/to/wp-content/rudel-environments/my-sandbox-a1b2
wp post list
```

Any `wp` command run from within the sandbox directory is automatically scoped to that sandbox's database and content.

## Features

- **Instant creation** -- sandboxes are created in under 2 seconds
- **Full isolation** -- each sandbox gets its own database and wp-content
- **Three engines** -- MySQL (default), SQLite for portable isolation, multisite sub-site for network environments
- **Database cloning** -- clone your host database into a sandbox with automatic URL rewriting
- **Snapshots** -- point-in-time snapshots with instant restore
- **Templates** -- save sandboxes as reusable starting points
- **Export & Import** -- package sandboxes as zip archives
- **GitHub workflows** -- push sandbox changes and open PRs without a local git binary
- **App mode** -- permanent domain-routed environments for client sites and multi-tenant hosting
- **Policy metadata** -- owner, labels, purpose, protection, expiry, and deploy lineage
- **Deploy history** -- app deploy plans, deployment records, and rollback by deployment ID
- **Automation** -- configurable cleanup, scheduled app backups, retention, and expiry reporting
- **Hooks** -- documented lifecycle actions and filters exposed through a stable catalog
- **Agent ready** -- scoped WP-CLI and CLAUDE.md support per sandbox

## How It Works

On activation, Rudel adds a single line to `wp-config.php` that loads a bootstrap file before WordPress boots. This bootstrap detects environment context from the incoming request via domain, explicit path prefix, header, subdomain, or a scoped preview cookie and rewires WordPress constants to point to the isolated environment. Runtime state lives in the host WordPress database, not JSON files, so WordPress-native code can reference environments and apps by stable DB IDs. When no environment is active, WordPress boots normally with zero overhead.

By default, sandboxes use MySQL with an isolated table prefix. Pass `--engine=sqlite` for file-based SQLite isolation, or `--engine=subsite` on multisite installations to create sandboxes as native sub-sites. SQLite only applies to sandbox site databases; Rudel's own apps, environments, worktrees, deployments, and config always live in the host WordPress MySQL database.

Each sandbox is a self-contained directory:

```
/wp-content/rudel-environments/{id}/
├── wp-cli.yml        # Auto-scopes all WP-CLI commands
├── bootstrap.php     # Sets WP constants for this sandbox
├── wordpress.db      # SQLite database (only with --engine=sqlite)
├── CLAUDE.md         # Agent instructions (optional)
├── wp-content/       # Isolated themes, plugins, uploads
├── snapshots/        # Named snapshots (on demand)
└── tmp/              # Sandbox temp directory
```

Apps use the same isolation layer, but live under `wp-content/rudel-apps/{id}/`. Their canonical browser URL is the mapped domain, and they can also be opened through `/{prefix}/{app-id}/` when you need a same-origin preview surface for an operator UI or embedded tooling.

Runtime records live in WordPress tables:

- `wp_rudel_environments`
- `wp_rudel_apps`
- `wp_rudel_app_domains`
- `wp_rudel_worktrees`
- `wp_rudel_app_deployments`

Those tables are the only supported source of truth for apps, environments, worktrees, deploy history, and domain routing. Rudel's cleanup and automation settings live alongside them in the host WordPress database through the `wp_options` row `rudel_config`.

For advanced embedding and theme-style installs, you can change only the Rudel portion of those table names by defining `RUDEL_RUNTIME_TABLE_PREFIX` before Rudel installs or bootstraps its persisted runtime tables. Explicit per-table constants like `RUDEL_RUNTIME_TABLE_ENVIRONMENTS` still win over the shared prefix. The WordPress DB prefix itself stays untouched, so `wp_rudel_environments` can become `wp_themeworkspace_environments`, but never `customprefix_themeworkspace_environments`.

## WP-CLI Commands

### Sandbox commands

| Command | Description |
|---------|-------------|
| `wp rudel create --name=<name>` | Create a new sandbox |
| `wp rudel create --name=<name> --engine=sqlite` | Create with SQLite engine |
| `wp rudel create --name=<name> --clone-all` | Clone host DB + wp-content |
| `wp rudel create --name=<name> --clone-from=<id>` | Clone from an existing sandbox or app |
| `wp rudel create --name=<name> --template=<name>` | Create from template |
| `wp rudel list` | List all sandboxes |
| `wp rudel info <id>` | Show sandbox details |
| `wp rudel update <id> [--owner=<owner>] [--labels=<labels>] [--protected]` | Update sandbox metadata and cleanup policy |
| `wp rudel destroy <id>` | Delete a sandbox |
| `wp rudel status` | Show Rudel status and config |
| `wp rudel logs <id>` | View or clear a sandbox debug log |
| `wp rudel snapshot <id> --name=<name>` | Create a snapshot |
| `wp rudel restore <id> --snapshot=<name>` | Restore from snapshot |
| `wp rudel export <id> --output=<path>` | Export as zip archive |
| `wp rudel import <file> --name=<name>` | Import from zip archive |
| `wp rudel cleanup` | Remove sandboxes matched by expiry, age, or idle policy |
| `wp rudel promote <id> [--force]` | Replace the host site with a sandbox |
| `wp rudel template save <id> --name=<name>` | Save sandbox as template |
| `wp rudel template list` | List templates |
| `wp rudel template delete <name>` | Delete a template |

### GitHub commands

| Command | Description |
|---------|-------------|
| `wp rudel push <id> --github=<repo>` | Push sandbox changes to a GitHub branch |
| `wp rudel pr <id> [--github=<repo>]` | Create a pull request from a sandbox branch |

### App commands

| Command | Description |
|---------|-------------|
| `wp rudel app create --domain=<domain>` | Create a permanent domain-routed app, optionally tracking a GitHub repo/branch/path |
| `wp rudel app update <id> [--owner=<owner>] [--labels=<labels>] [--protected]` | Update app metadata and lifecycle policy |
| `wp rudel app create-sandbox <id>` | Create a sandbox cloned from an app |
| `wp rudel app backup <id> --name=<name>` | Create an app backup |
| `wp rudel app backups <id>` | List backups for an app |
| `wp rudel app deployments <id>` | List deployment records for an app |
| `wp rudel app restore <id> --backup=<name>` | Restore an app from a backup |
| `wp rudel app deploy <id> --from=<sandbox-id>` | Deploy a sandbox into an app, with optional `--dry-run` planning |
| `wp rudel app rollback <id> --deployment=<deployment-id>` | Roll an app back using the backup referenced by a deployment record |
| `wp rudel app list` | List all apps |
| `wp rudel app info <id>` | Show app details |
| `wp rudel app destroy <id>` | Delete an app and remove its domain mappings |
| `wp rudel app domain-add <id> --domain=<domain>` | Add a domain to an app |
| `wp rudel app domain-remove <id> --domain=<domain>` | Remove a domain from an app |

## Sandbox Access

Sandboxes can be accessed via:

| Method | Example |
|--------|---------|
| Path prefix | `example.com/__rudel/sandbox-123/` |
| Subdomain | `sandbox-123.example.com` (requires wildcard DNS) |
| Header | `X-Rudel-Sandbox: sandbox-123` |
| Scoped preview cookie | Automatically set after visiting `example.com/__rudel/sandbox-123/` |

The path prefix method works out of the box with no DNS configuration.

Visiting a prefixed preview URL automatically sets a `rudel_sandbox` cookie scoped to `/{prefix}/{id}/`. That cookie helps direct entrypoints like `wp-login.php` and `wp-admin/` stay inside the same previewed environment, but it never activates the host root or host `/wp-admin/`. Append `?adminExit` to any prefixed preview URL to clear the scoped cookie and return to the host.

Apps are accessed directly by their mapped domains with no path prefix or preview cookie. When you need same-origin embedding or review flows, you can also open an app through `example.com/__rudel/{app-id}/`, which behaves like a subpath preview of that app.

Prefixed preview URLs are intended to behave like a subdirectory site:

- `/{prefix}/{id}/` serves the front page
- `/{prefix}/{id}/wp-admin/` stays inside that environment's admin flow
- `/{prefix}/{id}/wp-login.php` stays inside that environment's login flow
- `/{prefix}/{id}/wp-content/...` and `/{prefix}/{id}/wp-admin/...` static assets stay inside the same prefixed environment

Sandboxes are the place changes happen. Apps are the place those changes land. If you think in Git terms, sandboxes are closer to feature workspaces and apps are closer to deployed mainline state, but the analogy is conceptual because both also carry database and environment state.

See [CHANGELOG.md](./CHANGELOG.md) for the release history.

## Development

```bash
# Install dependencies
composer install

# Check coding standards
composer cs

# Run static analysis for src/ and cli/
composer stan

# Auto-fix coding standards
composer cs:fix

# Run PHPUnit
composer test

# Run the full validation suite
bash tests/run-all.sh

# Run specific test suites
composer test:unit
composer test:integration
composer test:security

# Run E2E tests
bash tests/e2e/run-all.sh
```

## Documentation

Full documentation at [rudel.dev](https://rudel.dev).

## License

GPL-2.0-or-later
