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
  Request-selected WordPress overlay environments for sandboxes.
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin and library for running disposable overlay sandboxes inside one normal WordPress installation.

A sandbox is selected per request with profile-configured selectors: a trusted header, cookie, or CLI environment variable. Once selected, Rudel switches WordPress to that sandbox's cloned database tables and copied active theme. Host WordPress core, plugins, uploads, and users remain shared by default.

Rudel does not require multisite. It does not use SQLite. Runtime metadata is stored only in host WordPress MySQL tables. Runtime names are supplied by an explicit integration profile, so Rudel can be embedded without leaking Rudel-specific constants, cookies, table names, or generated file names into the host application.

## Requirements

- PHP 8.2+
- WordPress 6.4+
- MySQL/MariaDB through the normal WordPress database connection
- write access to `wp-config.php` during initial runtime bootstrap installation

## Quick Start

```bash
composer require rudel/rudel
```

Install a runtime profile before activating Rudel. The profile names your selectors, constants, runtime tables, environment paths, and generated MU-plugin surface. Rudel fails closed when no profile is present.

Profiles can be supplied through the `rudel_runtime_profile` WordPress filter before activation, or through `$GLOBALS['rudel_runtime_profile']` before `bootstrap.php` runs. The injected bootstrap line stores the validated profile in a generated PHP config file so early request routing can load it before WordPress finishes booting.

Then activate the plugin and check status:

```bash
wp plugin activate rudel
wp rudel status
```

Create a sandbox:

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Run WP-CLI against a sandbox using the environment variable configured by your profile:

```bash
YOUR_ENV_SELECTOR=alpha-1234 wp option get siteurl
```

Route an HTTP request to a sandbox using the header configured by your profile:

```bash
curl -H 'X-Your-Environment: alpha-1234' http://localhost:8000/
```

## Runtime Model

Every Rudel environment is a sandbox overlay:

- Each sandbox has its own WordPress table prefix, for example `wp_a4nmv7_`.
- Each sandbox gets a copied active theme under the profile-configured environments directory, for example `wp-content/runtime-environments/{id}/themes/{theme}`.
- Child themes keep their parent template relationship; Rudel copies the child and parent theme directories when the selected active theme is a child theme.
- Plugins and uploads are intentionally shared from the host WordPress installation.
- Users are intentionally shared for now through the normal WordPress users tables.
- Request selection controls whether WordPress uses host state or one sandbox's state.

The selected sandbox does not replace `WP_CONTENT_DIR`. Rudel overrides the active theme root for the selected theme and switches the WordPress table prefix before core finishes booting.

## Runtime State

Rudel stores operational metadata in profile-configured WordPress tables. A profile might choose names such as:

- `wp_runtime_environments`
- `wp_runtime_worktrees`

Those tables are the source of truth for sandbox identity, worktrees, lifecycle policy, snapshots, and cleanup metadata.

Sandbox site data lives in cloned WordPress tables using the sandbox prefix. Rudel metadata tables always live in the host WordPress database. There is no runtime JSON config and no per-sandbox SQLite database.

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
