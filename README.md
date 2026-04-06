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

Rudel is a WordPress plugin for running disposable sandboxes and long-lived apps on a subdomain multisite network.

Every Rudel environment is a real multisite site with a real `blog_id`, a real URL, normal `wp-admin`, normal login, normal REST requests, and normal asset handling. Rudel builds the operator layer around that runtime: creation, cloning, templates, recovery points, deploy history, worktrees, and lifecycle metadata.

That gives you an environment system that feels native in the browser and deliberate enough for QA, agent workflows, demos, staged change work, and long-lived app operations.

## Requirements

- PHP 8.0+
- WordPress 6.4+
- WordPress multisite configured for subdomains
- write access to `wp-config.php` during initial runtime installation

## Quick Start

Start with a working subdomain multisite network, then install and activate Rudel:

```bash
composer require rudel/rudel
wp plugin activate rudel
wp rudel status
```

Create a sandbox for change work:

```bash
wp rudel create --name=alpha
```

Create an app for long-lived runtime state:

```bash
wp rudel app create --name=demo --domain=demo.example.test
```

Rudel creates real multisite sites, so use a core command to confirm the browser URLs:

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

Rudel has two lifecycle shapes, but one runtime model.

**Sandboxes** are the disposable side. They are where you try a migration, hand work to an agent, reproduce a bug, review a change, or test a risky update without touching the app that matters.

**Apps** are the durable side. They are the sites you keep around, back up, deploy into, restore from, and attach domain metadata to over time.

Both are multisite sites. If Rudel gives you an environment URL, that URL is the site you visit.

What Rudel adds around that runtime is the workflow layer:

- app-derived sandboxes
- reusable templates
- point-in-time sandbox snapshots
- app backups, deploys, and rollback
- worktree-aware code flows for git-tracked themes and plugins
- policy metadata such as owner, labels, protection, and expiry

## Runtime State

Rudel stores operational metadata in WordPress tables:

- `wp_rudel_environments`
- `wp_rudel_apps`
- `wp_rudel_app_domains`
- `wp_rudel_worktrees`
- `wp_rudel_app_deployments`

Those tables are the source of truth for environments, app identity, domains, worktrees, deployment history, and lifecycle policy.

Generated environment directories have a specific operator purpose. They hold the practical files Rudel needs around an environment: scoped `wp-cli.yml`, bootstrap files, logs, snapshots, backups, and other environment-owned artifacts. Rudel records code and worktree relationships in runtime tables rather than asking operators to infer them from filesystem layout.

## WP-CLI Surface

The CLI follows the same mental model as the product.

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

The naming is intentionally boring: sandboxes sit at `wp rudel ...`, while long-lived app operations sit at `wp rudel app ...`. Once that split clicks, the rest of the command surface is easy to navigate.

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
