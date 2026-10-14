---
title: "Getting Started"
description: "Install Rudel and create your first sandbox."
path: "getting-started"
order: 20
meta_title: "Getting Started"
meta_description: "Install Rudel and create your first sandbox."
---

# Getting Started

## Requirements

- PHP 8.2+
- WordPress 6.4+
- MySQL or MariaDB through the normal WordPress database connection
- a writable `wp-config.php` when the plugin is activated

## Install

Install Rudel with Composer and activate it like a normal WordPress plugin.

```bash
composer require rudel/rudel
wp plugin activate rudel
```

Composer installs Rudel into `vendor/rudel/rudel` unless your project maps WordPress plugins into `wp-content/plugins` (for example with `composer/installers`). The plugin must be in the plugins directory for `wp plugin activate rudel` to find it.

## What activation does

Activation creates Rudel's tables, schedules the hourly cleanup event and adds one bootstrap line to `wp-config.php`, directly above the line that loads `wp-settings.php`. That line lets Rudel select a sandbox before WordPress finishes loading. Rudel writes a backup copy named `wp-config.php.rudel-backup-{timestamp}-{suffix}.php` first, with private permissions and a PHP guard that rejects HTTP requests. Older plaintext backups are migrated. To restore a backup, remove its first guard line before replacing `wp-config.php`.

There is no separate install command. If `wp-config.php` is not writable, activation fails. Deactivation removes the line and the scheduled event.

Check the runtime status:

```bash
wp rudel status
```

`Bootstrap installed` should read `yes`.

## Create a sandbox

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Rudel prints the generated sandbox ID, for example `alpha-3be7`. It clones the host WordPress tables into a sandbox-specific table prefix, copies the theme into the sandbox directory, and stores the record in the host WordPress database. The examples below use `alpha-3be7`; use the ID your command printed.

## Use WP-CLI inside a sandbox

```bash
RUDEL_ENVIRONMENT=alpha-3be7 wp option get blogname
```

Inside a sandbox directory, its generated `wp-cli.yml` loads a selector before WordPress boots, so ordinary commands such as `wp post list` use that sandbox. An explicit `RUDEL_ENVIRONMENT` takes precedence. From the host directory, set `RUDEL_ENVIRONMENT` to select a sandbox.

## Route HTTP into a sandbox

```bash
curl -H 'X-Rudel-Environment: alpha-3be7' http://localhost:8000/
```

For a browser session, set a `rudel_environment` cookie with the sandbox ID. Rudel reads the cookie but never sets or clears it. Delete the cookie to return to the host.

The sandbox runs on the host URL. Without the header or cookie, the same URL serves the host.

## Next steps

- [Creating Environments](/docs/environments/creating)
- [Managing Environments](/docs/environments/managing)
- [Isolation](/docs/environments/isolation)
- [CLI](/docs/cli)
