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
  WordPress sandboxes powered by SQLite
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/github/license/inline0/rudel.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin that creates fully isolated sandbox environments within an existing WordPress installation. Each sandbox gets its own SQLite database, `wp-content` directory, and WP-CLI scope.

**Use cases:**
- Test plugin and theme changes without touching your live site
- Give AI coding agents (Claude Code, Cursor) a safe, scoped WordPress environment
- Spin up disposable environments for demos, QA, or client reviews
- Snapshot and restore at any point

## Quick Start

**Prerequisites:**
- PHP 8.0+
- WordPress 6.4+
- PDO SQLite PHP extension

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
cd /path/to/sandboxes/my-sandbox-a1b2
wp post list
```

Any `wp` command run from within the sandbox directory is automatically scoped to that sandbox's database and content.

## Features

- **Instant creation** — sandboxes are created in under 2 seconds
- **Full isolation** — each sandbox gets its own SQLite database and wp-content
- **Database cloning** — clone your host MySQL into a sandbox SQLite database
- **Snapshots** — point-in-time snapshots with instant restore
- **Templates** — save sandboxes as reusable starting points
- **Export & Import** — package sandboxes as zip archives
- **Auto cleanup** — configurable expiry and automatic removal of stale sandboxes
- **Agent ready** — scoped WP-CLI and CLAUDE.md support per sandbox

## How It Works

On activation, Rudel adds a single line to `wp-config.php` that loads a bootstrap file before WordPress boots. This bootstrap detects sandbox context from the incoming request and sets all WordPress constants to point to an isolated sandbox folder. When no sandbox is active, WordPress boots normally with zero overhead.

Each sandbox is a self-contained directory:

```
/sandboxes/sandbox-{id}/
├── .rudel.json       # Sandbox metadata
├── wp-cli.yml        # Auto-scopes all WP-CLI commands
├── bootstrap.php     # Sets WP constants for this sandbox
├── wordpress.db      # SQLite database
├── CLAUDE.md         # Agent instructions (optional)
├── wp-content/       # Isolated themes, plugins, uploads
├── snapshots/        # Named snapshots (on demand)
└── tmp/              # Sandbox temp directory
```

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp rudel create --name=<name>` | Create a new sandbox |
| `wp rudel create --name=<name> --clone-all` | Clone host DB + wp-content |
| `wp rudel create --name=<name> --clone-from=<id>` | Clone from existing sandbox |
| `wp rudel create --name=<name> --template=<name>` | Create from template |
| `wp rudel list` | List all sandboxes |
| `wp rudel info <id>` | Show sandbox details |
| `wp rudel destroy <id>` | Delete a sandbox |
| `wp rudel status` | Show Rudel status and config |
| `wp rudel snapshot <id> --name=<name>` | Create a snapshot |
| `wp rudel restore <id> --snapshot=<name>` | Restore from snapshot |
| `wp rudel export <id> --output=<path>` | Export as zip archive |
| `wp rudel import <file> --name=<name>` | Import from zip archive |
| `wp rudel cleanup` | Remove expired sandboxes |
| `wp rudel template save <id> --name=<name>` | Save sandbox as template |
| `wp rudel template list` | List templates |
| `wp rudel template delete <name>` | Delete a template |

## Sandbox Access

Sandboxes can be accessed via:

| Method | Example |
|--------|---------|
| Path prefix | `example.com/__rudel/sandbox-123/` |
| Subdomain | `sandbox-123.example.com` (requires wildcard DNS) |
| Header | `X-Rudel-Sandbox: sandbox-123` |
| Cookie | `rudel_sandbox=sandbox-123` |

The path prefix method works out of the box with no DNS configuration.

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
