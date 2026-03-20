# Rudel

WordPress plugin for sandboxed environments. Each sandbox gets its own isolated database (MySQL by default, SQLite optional), wp-content directory, and wp-cli scope.

## Quick Reference

```bash
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

## Project Structure

```
rudel/
├── rudel.php            # Entry point
├── bootstrap.php        # Pre-boot sandbox resolver (loaded via wp-config.php)
├── phpunit.xml.dist     # PHPUnit configuration
├── phpcs.xml            # PHPCS configuration
├── .wp-env.json         # Docker-based wp-env config
├── src/
│   ├── ConfigWriter.php   # wp-config.php line management
│   ├── ContentCloner.php  # Copies wp-content dirs from host to sandbox
│   ├── DatabaseCloner.php # Clones host MySQL to sandbox SQLite
│   ├── MySQLCloner.php    # Clones host MySQL tables with sandbox prefix
│   ├── RudelConfig.php    # JSON config (limits, cleanup settings)
│   ├── Sandbox.php        # Sandbox model (engine: mysql or sqlite)
│   ├── SandboxManager.php # CRUD orchestrator + export/import/cleanup
│   ├── SnapshotManager.php # Point-in-time snapshots per sandbox
│   ├── TemplateManager.php # Reusable sandbox templates
│   └── Router.php         # Request to sandbox resolution
├── cli/
│   ├── RudelCommand.php    # WP-CLI commands (rudel)
│   └── TemplateCommand.php # WP-CLI commands (rudel template)
├── templates/           # Template files for generated sandbox files
├── lib/                 # Bundled SQLite integration (auto-downloaded)
└── tests/
    ├── bootstrap.php    # PHPUnit bootstrap
    ├── RudelTestCase.php # Shared base test class
    ├── Stubs/
    │   ├── wp-cli-stubs.php
    │   └── MockWpdb.php
    ├── Unit/            # PHPUnit unit tests
    ├── Integration/     # PHPUnit integration tests
    ├── Security/        # PHPUnit security tests
    └── e2e/             # Shell-based end-to-end tests
```

## Comment Policy

- Internal code: no JSDoc. Comments only for why, not what.
- Public APIs: JSDoc required (description + params/returns/examples).
- Tests: no redundant comments that restate test names. Comment only when setup/assertion is non-obvious.
- **No banner comments**: never use decorative separator lines like `// ==========`, `// -----`, `// ===== SECTION =====`, etc. In large test files with many assertions, a single `// Section Name` line is fine to separate groups.
- **No em dashes**: never use em dashes in code, docs, or copy. Use periods, commas, colons, or rewrite the sentence.

## Configuration

Both constants are optional. Define them in `wp-config.php` before the `require bootstrap.php` line.

| Constant | Default | Description |
|----------|---------|-------------|
| `RUDEL_CLI_COMMAND` | `rudel` | WP-CLI command name (e.g. `wp rudel create`) |
| `RUDEL_PATH_PREFIX` | `__rudel` | URL path prefix for sandbox access (e.g. `/__rudel/{id}/`) |

## Key Rules

1. **100% WordPress Coding Standards**: no exceptions. Run `composer cs` before committing.
2. **Run tests after changes**: `composer test` for PHPUnit, `bash tests/e2e/run-all.sh` for E2E.
3. **bootstrap.php is self-contained**: no autoloader, no WP functions, plain PHP only. Changes to Router logic must be manually propagated to bootstrap.php.
4. **Filesystem is source of truth**: `.rudel.json` per sandbox, no central registry.
5. **Never modify `lib/`**: auto-downloaded SQLite integration, treated as vendor code.

## Commands

| Command | Description |
|---------|-------------|
| `composer cs` | Check PHPCS |
| `composer cs:fix` | Auto-fix PHPCS issues |
| `composer test` | Run all PHPUnit tests |
| `composer test:unit` | Run unit tests only |
| `composer test:integration` | Run integration tests only |
| `composer test:security` | Run security tests only |
| `bash tests/e2e/run-all.sh` | Run all E2E shell tests |
| `bash tests/e2e/test-wp-env.sh` | Run wp-env E2E tests (requires Docker) |
| `bash tests/e2e/test-clone.sh` | Run clone E2E tests (requires Docker) |

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp rudel create --name=<name> [--template=<template>]` | Create a blank sandbox (or from template) |
| `wp rudel create --name=<name> --clone-all` | Clone host DB + wp-content into sandbox |
| `wp rudel create --name=<name> --clone-db` | Clone host DB only |
| `wp rudel create --name=<name> --clone-themes --clone-plugins` | Clone specific content |
| `wp rudel create --name=<name> --clone-from=<id>` | Clone from an existing sandbox |
| `wp rudel list [--format=<format>]` | List all sandboxes |
| `wp rudel info <id> [--format=<format>]` | Show sandbox details |
| `wp rudel destroy <id> [--force]` | Delete a sandbox |
| `wp rudel status` | Show Rudel status and config |
| `wp rudel snapshot <id> --name=<name>` | Create a snapshot of a sandbox |
| `wp rudel restore <id> --snapshot=<name> [--force]` | Restore a sandbox from a snapshot |
| `wp rudel export <id> --output=<path>` | Export a sandbox as a zip archive |
| `wp rudel import <file> --name=<name>` | Import a sandbox from a zip archive |
| `wp rudel cleanup [--dry-run] [--max-age-days=<days>]` | Clean up expired sandboxes |
| `wp rudel template save <id> --name=<name>` | Save a sandbox as a template |
| `wp rudel template list [--format=<format>]` | List all templates |
| `wp rudel template delete <name> [--force]` | Delete a template |
