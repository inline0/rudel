---
title: "Configuration"
description: "The rudel_config settings and the PHP constants Rudel reads."
path: "configuration"
order: 165
section: "Reference"
meta_title: "Configuration"
meta_description: "Reference for Rudel configuration: the rudel_config option with limits, cleanup and automation keys, and the wp-config.php constants Rudel reads."
---

# Configuration

Rudel has two kinds of configuration: settings stored in the `rudel_config` WordPress option, and constants you define in `wp-config.php`.

## The `rudel_config` option

Settings live in one array in `wp_options` under `rudel_config`. Until something writes the option, every key uses its default. Unknown keys are ignored and values are stored as integers.

| Key | Default | Meaning |
|-----|---------|---------|
| `max_sandboxes` | `0` | Refuse to create a sandbox when this many exist. `0` means no limit. |
| `max_disk_mb` | `0` | Refuse to create a sandbox when the sandbox directories use this many megabytes. Cloned tables are not counted. `0` means no limit. |
| `default_ttl_days` | `0` | Expiry given to new sandboxes created without `ttl_days` or `expires_at`. `0` means no expiry. |
| `max_age_days` | `0` | Cleanup removes sandboxes older than this. `0` disables the age rule. |
| `max_idle_days` | `0` | Cleanup removes sandboxes with no recorded activity (a sandbox request or snapshot) for this long. `0` disables the idle rule. |
| `auto_cleanup_enabled` | `1` | Run the cleanup policy from WP-Cron every hour. |
| `auto_cleanup_merged` | `0` | Also run merged-branch cleanup from WP-Cron. |
| `expiring_environment_notice_days` | `0` | Collect sandboxes expiring within this many days on each automation run and pass them to the `rudel_after_automation_expiring_environments` action. |
| `auto_snapshot_before_restore` | `1` | Save a `pre-restore-...` snapshot before every restore. |

`wp rudel status` prints the current values.

Write the option with WP-CLI. Keys you leave out keep their defaults:

```bash
wp option update rudel_config '{"max_age_days":14,"max_idle_days":3,"auto_cleanup_merged":1}' --format=json
```

Or from PHP:

```php
$config = new Rudel\RudelConfig();
$config->set( 'max_sandboxes', 20 );
$config->save();
```

The WP-Cron event is scheduled or removed on the next request after a change. See [Automation](/docs/features/automation).

## Constants

Define these in `wp-config.php` above the Rudel bootstrap line.

| Constant | Default | Meaning |
|----------|---------|---------|
| `RUDEL_CLI_COMMAND` | `rudel` | Root WP-CLI command name. |
| `RUDEL_ENVIRONMENTS_DIR` | `wp-content/rudel-environments` | Directory that holds sandbox directories. Theme assets are always served from `/wp-content/rudel-environments/{id}/themes`, so another directory must still be reachable at that URL. |
| `RUDEL_RUNTIME_TABLE_PREFIX` | `rudel_` | Part of the Rudel table names after the WordPress prefix. See [Runtime State](/docs/features/runtime-state). |
| `RUDEL_RUNTIME_TABLE_ENVIRONMENTS` | | Full name override for the environments table, after the WordPress prefix. |
| `RUDEL_RUNTIME_TABLE_WORKTREES` | | Full name override for the worktrees table, after the WordPress prefix. |
| `RUDEL_DB_DRIVER` | `auto` | Driver for standalone `Rudel\Connection` access: `auto`, `mysqli` or `pdo`. WordPress requests always use `$wpdb`. |
| `RUDEL_GIT_AUTHOR_NAME` | `Rudel` | Author name Rudel writes into a checkout's Git config when it has no `user.name`, used for `wp rudel push` commits. |
| `RUDEL_GIT_AUTHOR_EMAIL` | `rudel@localhost` | Author email Rudel writes when the checkout has no `user.email`. |

## Constants inside a sandbox request

When a request selects a sandbox, the bootstrap defines constants that code can read. The most useful are:

| Constant | Value |
|----------|-------|
| `RUDEL_ID` | sandbox ID |
| `RUDEL_PATH` | sandbox directory |
| `RUDEL_TABLE_PREFIX` | sandbox table prefix |
| `RUDEL_THEME_SLUG` | theme served from the sandbox |
| `RUDEL_HOST_URL`, `RUDEL_ENVIRONMENT_URL` | host and sandbox URLs |
| `RUDEL_DISABLE_EMAIL` | `true`; outbound mail is blocked |

Prefer the [API](/docs/api) methods such as `Rudel::id()` over reading the constants directly.

The bootstrap supplies missing debug, temporary-directory and cache-salt constants. Effective auth salts are derived through WordPress's `salt` filter using its secret and the selected environment ID. PHP log routing and display settings apply after WordPress's debug setup, even when the host already defined its debug constants. See [Error Logging](/docs/features/logging) and [Isolation](/docs/environments/isolation).
