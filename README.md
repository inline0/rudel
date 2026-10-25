<p align="center">
  <a href="https://rudel.inline0.dev">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="./.github/logo-dark.svg">
      <source media="(prefers-color-scheme: light)" srcset="./.github/logo-light.svg">
      <img alt="Rudel" src="./.github/logo-light.svg" height="50">
    </picture>
  </a>
</p>

<p align="center">
  Request-selected WordPress overlay environments for sandboxes.
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin for running disposable overlay sandboxes inside one normal WordPress installation.

A sandbox is selected per request with a header, a cookie, or a CLI environment variable. Once selected, Rudel switches WordPress to that sandbox's cloned database tables, users included, and its copied active theme. WordPress core, plugin files, and uploads stay shared with the host.

Rudel does not require multisite. It does not use SQLite. Runtime metadata is stored only in host WordPress MySQL tables.

## Requirements

- PHP 8.2+
- WordPress 6.4+
- MySQL/MariaDB through the normal WordPress database connection
- write access to `wp-config.php` when the plugin is activated (activation adds the bootstrap line)

## Quick Start

```bash
composer require rudel/rudel
wp plugin activate rudel
wp rudel status
```

Activation installs the early bootstrap line in `wp-config.php`; there is no separate install command. Composer must place the package in `wp-content/plugins` (for example through `composer/installers`) for `wp plugin activate` to find it.

Create a sandbox. The command prints the generated ID, for example `alpha-1234`:

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Run WP-CLI against a sandbox:

```bash
RUDEL_ENVIRONMENT=alpha-1234 wp option get siteurl
```

Route an HTTP request to a sandbox:

```bash
curl -H 'X-Rudel-Environment: alpha-1234' http://localhost:8000/
```

## Runtime Model

Every Rudel environment is a sandbox overlay:

- Each sandbox has its own WordPress table prefix, for example `wp_b65338d_`, holding a copy of the host tables.
- Each sandbox gets a copied active theme under `wp-content/rudel-environments/{id}/themes/{theme}`.
- Child themes keep their parent template relationship; Rudel copies the child and parent theme directories when the selected active theme is a child theme.
- Plugins and uploads are intentionally shared from the host WordPress installation.
- Users are cloned with the other tables when the sandbox is created; after that, sandbox and host users change independently.
- Request selection controls whether WordPress uses host state or one sandbox's state. The selector is not authenticated, so it is not an access-control boundary.

The selected sandbox does not replace `WP_CONTENT_DIR`. Rudel overrides the active theme root for the selected theme and switches the WordPress table prefix before core finishes booting.

## Runtime State

Rudel stores operational metadata in WordPress tables:

- `wp_rudel_environments`
- `wp_rudel_worktrees`

Those tables are the source of truth for sandbox identity, worktrees, and lifecycle metadata such as owner, protection, and expiry. Settings live in the `rudel_config` option.

Sandbox site data lives in cloned WordPress tables using the sandbox prefix; snapshots are further table copies. Rudel metadata tables always live in the host WordPress database. There is no runtime JSON config and no per-sandbox SQLite database.

## WP-CLI Surface

- `wp rudel create`
- `wp rudel list`
- `wp rudel info`
- `wp rudel update`
- `wp rudel destroy`
- `wp rudel status`
- `wp rudel cleanup`
- `wp rudel logs`
- `wp rudel snapshot`
- `wp rudel restore`
- `wp rudel template save`
- `wp rudel template list`
- `wp rudel template delete`
- `wp rudel push`

## Standalone Core Access

Rudel's registry can be inspected outside a WordPress request with a direct MySQL connection. Standalone mode is for metadata and registry access: list environments, inspect worktrees, and read lifecycle state.

Operations that clone WordPress tables, copy themes, install the bootstrap, or execute live sandbox lifecycle still require WordPress because they depend on the active WordPress database connection and filesystem layout.

## Development

```bash
composer install
pnpm install --frozen-lockfile

composer cs
composer stan
composer test

node tests/e2e/run-all.ts
node scripts/check-docs-content.ts
node tests/run-all.ts
```

## Documentation

Full documentation lives as portable Markdown in [`docs/`](./docs).

## License

GPL-2.0-or-later
