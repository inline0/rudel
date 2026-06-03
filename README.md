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
  Request-selected WordPress overlay environments for sandboxes and apps.
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin for running disposable sandboxes and long-lived app environments inside one normal WordPress installation.

An environment is selected per request with a trusted header, Rudel cookie, CLI environment variable, or mapped app domain. Once selected, Rudel switches WordPress to that environment's cloned database tables and copied active theme. The host WordPress core, plugins, uploads, and users remain shared by default.

Rudel does not require multisite. It does not use SQLite. Runtime metadata is stored only in host WordPress MySQL tables.

## Requirements

- PHP 8.2+
- WordPress 6.4+
- MySQL/MariaDB through the normal WordPress database connection
- write access to `wp-config.php` during initial runtime bootstrap installation

## Quick Start

```bash
composer require rudel/rudel
wp plugin activate rudel
wp rudel status
```

Create a sandbox:

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Create an app:

```bash
wp rudel app create --name=demo --domain=demo.example.test --theme=twentytwentyfour
```

Run WP-CLI against an environment:

```bash
RUDEL_ENVIRONMENT=alpha-1234 wp option get siteurl
```

Route an HTTP request to an environment:

```bash
curl -H 'X-Rudel-Environment: alpha-1234' http://localhost:8000/
```

## Runtime Model

Rudel has two lifecycle shapes and one runtime model.

Sandboxes are disposable environments for tasks, agents, QA, bug reproduction, migrations, and risky change work.

Apps are durable environments for approved state. They keep domain metadata, backups, deploy history, worktrees, and long-lived lifecycle state.

Both are overlay environments:

- Each environment has its own WordPress table prefix, for example `wp_a4nmv7_`.
- Each environment gets a copied active theme under `wp-content/rudel-environments/{id}/themes/{theme}` or `wp-content/rudel-apps/{id}/themes/{theme}`.
- Child themes keep their parent template relationship; Rudel copies the child and parent theme directories when the selected active theme is a child theme.
- Plugins and uploads are intentionally shared from the host WordPress installation.
- Users are intentionally shared for now through the normal WordPress users tables.
- Request selection controls whether WordPress uses host state or one environment's state.

The selected environment does not replace `WP_CONTENT_DIR`. Rudel only overrides the active theme root for the selected theme and switches the WordPress table prefix before core finishes booting.

## Runtime State

Rudel stores operational metadata in WordPress tables:

- `wp_rudel_environments`
- `wp_rudel_apps`
- `wp_rudel_app_domains`
- `wp_rudel_worktrees`
- `wp_rudel_app_deployments`

Those tables are the source of truth for environments, app identity, domains, worktrees, deployment history, and lifecycle policy.

Environment site data lives in cloned WordPress tables using the environment prefix. Rudel metadata tables always live in the host WordPress database. There is no runtime JSON config and no per-environment SQLite database.

## WP-CLI Surface

Sandbox lifecycle:

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

App lifecycle:

- `wp rudel app create`
- `wp rudel app list`
- `wp rudel app info`
- `wp rudel app update`
- `wp rudel app destroy`
- `wp rudel app create-sandbox`
- `wp rudel app backup`
- `wp rudel app backups`
- `wp rudel app deployments`
- `wp rudel app restore`
- `wp rudel app deploy`
- `wp rudel app rollback`
- `wp rudel app domain-add`
- `wp rudel app domain-remove`

## Standalone Core Access

Rudel's registry can be inspected outside a WordPress request with a direct MySQL connection. Standalone mode is for metadata and registry access: list apps, list environments, inspect domains, inspect worktrees, and read deployment history.

Operations that clone WordPress tables, copy themes, install the bootstrap, or execute live environment lifecycle still require WordPress because they depend on the active WordPress database connection and filesystem layout.

## Development

```bash
composer install
npm install

composer cs
composer stan
composer test

bash tests/e2e/run-all.sh
npm --prefix docs run build
bash tests/run-all.sh
```

## Documentation

Full documentation lives at [rudel.dev](https://rudel.dev).

## License

GPL-2.0-or-later
