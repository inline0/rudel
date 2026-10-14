---
title: "Automation"
description: "Scheduled cleanup and expiry reporting."
path: "features/automation"
order: 90
section: "Features"
meta_title: "Automation"
meta_description: "Scheduled cleanup and expiry reporting."
---

# Automation

Rudel's automation keeps disposable sandboxes from accumulating forever. One hourly WP-Cron event, `rudel_scheduled_cleanup`, can:

- run the cleanup policy (expiry, age and idle)
- run merged-branch cleanup
- report sandboxes that are expired or close to expiry

## Configuration

Automation reads these keys from the `rudel_config` option. The full list is on [Configuration](/docs/configuration).

| Key | Purpose | Default |
|-----|---------|---------|
| `auto_cleanup_enabled` | Run the cleanup policy on each event | `1` |
| `auto_cleanup_merged` | Also remove sandboxes with merged Git worktree branches | `0` |
| `expiring_environment_notice_days` | Report sandboxes expiring within N days | `0` |
| `max_age_days` | Age limit used by the cleanup policy | `0` |
| `max_idle_days` | Idle limit used by the cleanup policy | `0` |
| `default_ttl_days` | Expiry given to new sandboxes | `0` |

With the defaults, the event is scheduled but only removes sandboxes that have an explicit expiry in the past, because no age or idle limit and no default TTL is set.

## WP-Cron

Activation schedules the event, and every request re-checks the schedule: the event exists while any of `auto_cleanup_enabled`, `auto_cleanup_merged` or `expiring_environment_notice_days` is non-zero, and is removed otherwise. Deactivation removes it.

There are no automation commands. Change the settings in `rudel_config`:

```bash
wp option update rudel_config '{"auto_cleanup_enabled":1,"max_idle_days":7,"expiring_environment_notice_days":2}' --format=json
wp rudel status
```

`wp rudel status` shows `Auto cleanup`, `Auto cleanup merged`, `Expiry notice days` and `Automation scheduled`.

## Expiry reports

When `expiring_environment_notice_days` is set, each run collects sandboxes with an `expires_at` within that many days and passes them to the `rudel_after_automation_expiring_environments` action, split into `expiring` and `expired`. Rudel sends no notifications itself; hook the action to notify owners.

```php
add_action(
    'rudel_after_automation_expiring_environments',
    function ( array $report ): void {
        foreach ( $report['expiring'] as $sandbox ) {
            error_log( $sandbox['id'] . ' expires at ' . $sandbox['expires_at'] );
        }
    }
);
```

## Running it now

Use manual cleanup for deterministic local or CI runs:

```bash
wp rudel cleanup --dry-run
wp rudel cleanup
```

From PHP, `Rudel::run_automation()` runs the same tasks as the cron event.
