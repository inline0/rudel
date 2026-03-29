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
- **Auto cleanup** -- configurable age, idle, expiry, and merged-branch cleanup for stale sandboxes
- **Agent ready** -- scoped WP-CLI and CLAUDE.md support per sandbox

## How It Works

On activation, Rudel adds a single line to `wp-config.php` that loads a bootstrap file before WordPress boots. This bootstrap detects environment context from the incoming request via domain, path prefix, cookie, header, or subdomain and rewires WordPress constants to point to the isolated environment. When no environment is active, WordPress boots normally with zero overhead.

By default, sandboxes use MySQL with an isolated table prefix. Pass `--engine=sqlite` for file-based SQLite isolation, or `--engine=subsite` on multisite installations to create sandboxes as native sub-sites.

Each sandbox is a self-contained directory:

```
/wp-content/rudel-environments/{id}/
├── .rudel.json       # Sandbox metadata
├── wp-cli.yml        # Auto-scopes all WP-CLI commands
├── bootstrap.php     # Sets WP constants for this sandbox
├── wordpress.db      # SQLite database (only with --engine=sqlite)
├── CLAUDE.md         # Agent instructions (optional)
├── wp-content/       # Isolated themes, plugins, uploads
├── snapshots/        # Named snapshots (on demand)
└── tmp/              # Sandbox temp directory
```

Apps use the same isolation layer, but live under `wp-content/rudel-apps/{id}/` and are reached by their mapped domains instead of a path prefix.

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
| `wp rudel app create --domain=<domain>` | Create a permanent domain-routed app |
| `wp rudel app update <id> [--owner=<owner>] [--labels=<labels>] [--protected]` | Update app metadata and lifecycle policy |
| `wp rudel app create-sandbox <id>` | Create a sandbox cloned from an app |
| `wp rudel app backup <id> --name=<name>` | Create an app backup |
| `wp rudel app backups <id>` | List backups for an app |
| `wp rudel app restore <id> --backup=<name>` | Restore an app from a backup |
| `wp rudel app deploy <id> --from=<sandbox-id>` | Deploy a sandbox into an app |
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
| Cookie | `rudel_sandbox=sandbox-123` |

The path prefix method works out of the box with no DNS configuration.

Visiting a sandbox URL automatically sets a cookie, so `/wp-admin/` works in sandbox context. Append `?adminExit` to any URL to return to the host.

Apps are accessed directly by their mapped domains with no path prefix or browser cookie.

Sandboxes are the place changes happen. Apps are the place those changes land. If you think in Git terms, sandboxes are closer to feature workspaces and apps are closer to deployed mainline state, but the analogy is conceptual because both also carry database and environment state.

## Development

```bash
# Install dependencies
composer install

# Check coding standards
composer cs

# Auto-fix coding standards
composer cs:fix

# Run all tests
composer test

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
