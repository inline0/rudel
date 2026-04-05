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
  WordPress environment orchestration on top of subdomain multisite.
</p>

<p align="center">
  <a href="https://github.com/inline0/rudel/actions/workflows/ci.yml"><img src="https://github.com/inline0/rudel/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/inline0/rudel/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0-blue.svg" alt="license"></a>
</p>

---

## What is Rudel?

Rudel is a WordPress plugin that manages disposable sandboxes and long-lived apps as real subdomain multisite sites.

Every Rudel environment gets:

- a real `blog_id`
- native multisite tables and uploads
- a generated environment directory with scoped `wp-cli.yml`
- isolated `wp-content` for copied themes, plugins, worktrees, snapshots, and backups
- runtime metadata stored in WordPress tables

Rudel does not emulate sites through synthetic browser paths. The runtime source of truth is WordPress multisite.

## Requirements

- PHP 8.0+
- WordPress 6.4+
- WordPress multisite configured for subdomains
- write access to `wp-config.php` during initial runtime installation

## Quick Start

```bash
composer require rudel/rudel
wp plugin activate rudel
wp rudel status
```

Create a sandbox:

```bash
wp rudel create --name=alpha
```

Create an app:

```bash
wp rudel app create --name=demo --domain=demo.example.test
```

List the real multisite URLs Rudel created:

```bash
wp site list --fields=blog_id,url
```

Run WP-CLI directly against one environment:

```bash
wp --url=http://alpha.localhost option get siteurl
```

Or work from its generated directory:

```bash
cd /path/to/wp-content/rudel-environments/alpha-1234
wp option get siteurl
```

## Current Model

Rudel builds on a subdomain multisite network.

- sandboxes are short-lived multisite sites for change work
- apps are long-lived multisite sites for stable runtime state
- app backups and deploy records are first-class
- snapshots belong to sandboxes
- templates capture reusable environment content
- worktrees and GitHub metadata stay attached to Rudel records

The browser/runtime contract is simple:

- each environment has a real site URL
- `wp-admin`, `wp-login.php`, REST, assets, and uploads work through that site
- routing is native multisite subdomain routing

## Runtime State

Rudel stores runtime metadata in WordPress tables:

- `wp_rudel_environments`
- `wp_rudel_apps`
- `wp_rudel_app_domains`
- `wp_rudel_worktrees`
- `wp_rudel_app_deployments`

Those tables are the source of truth for environments, apps, domains, worktrees, deployment history, and lifecycle metadata.

Environment directories hold generated bootstrap files, scoped `wp-cli.yml`, isolated `wp-content`, snapshots, backups, and worktree-managed code.

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
- `wp rudel pr`

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

## Development

```bash
composer install
npm install

composer cs
composer stan
composer test
composer test:integration
composer test:security

bash tests/e2e/run-all.sh
npm --prefix docs run build
bash tests/run-all.sh
```

## Documentation

Full documentation lives at [rudel.dev](https://rudel.dev).

## License

GPL-2.0-or-later
