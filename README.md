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

Every Rudel environment is a real multisite site with a real `blog_id`, a real URL, normal `wp-admin`, normal REST requests, normal asset handling, and its own isolated users. Each environment also has its own cloned `wp-content`, which is the canonical file and code tree for that environment. Rudel builds the operator layer around that runtime: creation, cloning, templates, recovery points, deploy history, worktrees, and lifecycle metadata.

That gives teams an environment system that feels like ordinary WordPress in
the browser while still giving operators explicit lifecycle tools for QA,
demos, staged change work, code review, and stable app operations.

## Requirements

- PHP 8.2+
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

## Runtime Model

Rudel has two lifecycle shapes, but one runtime model.

**Sandboxes** are the disposable side. They are where you try a migration, hand work to an agent, reproduce a bug, review a change, or test a risky update without touching the app that matters.

**Apps** are the durable side. They are the sites you keep around, back up, deploy into, restore from, and attach domain metadata to over time.

Both are multisite sites. If Rudel gives you an environment URL, that URL is
the site you visit.

Both also have their own environment-local `wp-content`. That cloned content
tree is the only code and file source of truth for that environment. Worktrees
live inside that environment-local tree as well, and an environment-local
`db.php` drop-in points WordPress at that environment's isolated user tables.

If you want a lighter-weight layout, Rudel also supports opt-in shared
`plugins` and `uploads`. That keeps the environment-local `wp-content` root
but links those two directories back to the host instead of copying them.
Themes stay local.

Apps add one extra rule on top of that: when an app has a primary mapped
domain, Rudel treats that domain as the app's canonical URL in its API,
deploy rewrites, and generated local tooling. The underlying multisite subsite
still exists as the deterministic runtime substrate.

What Rudel adds on top of that runtime is the operational surface:

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

Those tables always live in the host WordPress database. Rudel does not store
its own runtime metadata in JSON files or any parallel runtime database.
Current Rudel is multisite-only and uses the host WordPress database as the
only runtime store for its registry.

Outside WordPress, Rudel can still use that same registry through a standalone
DB connection. The DB-backed core can list apps and environments, inspect
deployments and worktrees, and read or update metadata without a live WordPress
request. WordPress multisite lifecycle work such as creating or destroying
sites still stays inside WordPress.

Generated environment directories hold the full environment-local file tree:
scoped `wp-cli.yml`, bootstrap files, the cloned `wp-content`, logs,
snapshots, backups, and other environment artifacts. Rudel records metadata
about worktrees and lifecycle state in runtime tables so operators can inspect
and query it directly.

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

## Clone Performance

Rudel keeps clone semantics broad: a new app or sandbox still gets a real
multisite site plus its own cloned `wp-content`. The performance work is in the
copy implementation, not by weakening that contract.

There is one explicit exception path for downstream products that inject a
runtime entry globally outside the environment: `content_exclude`. That lets
the caller skip explicitly named top-level entries in `themes`, `plugins`, or
`uploads` while leaving the rest of the clone broad.

Example:

```php
Rudel::create(
    'alpha',
    [
        'clone_plugins'   => true,
        'content_exclude' => [
            'plugins' => [ 'runtime-core' ],
        ],
    ]
);
```

Current local baseline from the reproducible benchmark:

```bash
bash tests/e2e/benchmark-wp-env.sh
```

Example result:

```json
{
  "app_create_ms": 1784,
  "sandbox_create_ms": 2060,
  "local_git_sandbox_create_ms": 1883
}
```

The copy stack is intentionally tiered:

- native batched tar copy when process execution is available
- batched `PharData` archive fallback for hosts that disable `proc_open`
- recursive PHP copy only as the last-resort fallback

One real downstream case mattered here: copying one globally bootstrapped
runtime plugin directory added roughly `937 MB` of redundant plugin data to
every app clone. Excluding only that one top-level plugin entry dropped the
measured create time on the same lane from roughly `22–32s` to roughly `2–4s`
without changing the broader clone contract for database state, themes,
uploads, or the rest of `wp-content`.

If clone performance regresses, measure it with `tests/e2e/benchmark-wp-env.sh`
before changing clone semantics or narrowing what gets copied.

## Standalone Core Access

If you need Rudel's registry outside WordPress, initialize it with a direct DB
connection:

```php
use Rudel\Connection;
use Rudel\Rudel;

$conn = new Connection(
    host: '127.0.0.1:3306',
    dbname: 'wordpress',
    user: 'root',
    password: 'secret',
    prefix: 'wp_',
);

Rudel::init(
    $conn,
    [
        'environments_dir' => '/var/www/html/wp-content/rudel-environments',
        'apps_dir' => '/var/www/html/wp-content/rudel-apps',
    ]
);

Rudel::ensure_schema();

$apps = Rudel::apps();
$sandboxes = Rudel::all();
```

That standalone path is for the DB-backed core. It does not replace the
WordPress adapter layer. Operations that create, destroy, or rewire multisite
sites still require a live WordPress multisite runtime.

For embedded control planes that share one WordPress database, pass a
connection-level Rudel table prefix:

```php
$conn = new Connection(
    host: '127.0.0.1:3306',
    dbname: 'wordpress',
    user: 'root',
    password: 'secret',
    prefix: 'wp_',
    table_prefix: 'divine_rudel_',
);
```

That keeps the WordPress database prefix intact and changes only the Rudel
portion of the runtime table names, for example
`wp_divine_rudel_environments`.

## Documentation

Full documentation lives at [rudel.dev](https://rudel.dev).

## License

GPL-2.0-or-later
