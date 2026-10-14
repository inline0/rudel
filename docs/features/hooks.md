---
title: "Hooks"
description: "Every Rudel action and filter with its arguments."
path: "features/hooks"
order: 100
section: "Features"
meta_title: "Hooks"
meta_description: "Reference for the Rudel hook catalog: lifecycle, snapshot, cleanup, Git push and automation actions, plus filters, with their arguments."
---

# Hooks

Rudel fires WordPress actions and filters around sandbox lifecycle events. The catalog below is what `Rudel::hooks()` returns: 28 actions and 5 filters.

## Example

```php
add_action(
    'rudel_after_environment_create',
    function ( \Rudel\Environment $environment, array $context ): void {
        error_log( 'Created Rudel environment ' . $environment->id );
    },
    10,
    2
);
```

Most `before` and `failed` actions receive a `$context` array. For sandbox lifecycle hooks it holds the `environment` (or the `name`, `options` and `environments_dir` on create); `failed` actions also receive the caught `Throwable` as `$error`.

## Sandbox lifecycle actions

| Action | Arguments |
|--------|-----------|
| `rudel_before_environment_create` | `$context` |
| `rudel_after_environment_create` | `$environment`, `$context` |
| `rudel_environment_create_failed` | `$context`, `$error` |
| `rudel_before_environment_update` | `$context` |
| `rudel_after_environment_update` | `$environment`, `$context` |
| `rudel_environment_update_failed` | `$context`, `$error` |
| `rudel_before_environment_destroy` | `$context` |
| `rudel_after_environment_destroy` | `$context` |
| `rudel_environment_destroy_failed` | `$context`, `$error` |
| `rudel_before_environment_replace_state` | `$context` |
| `rudel_after_environment_replace_state` | `$result`, `$context` |
| `rudel_environment_replace_state_failed` | `$context`, `$error` |

The three `replace_state` actions belong to `EnvironmentManager::replace_environment_state()`, which no Rudel command calls.

## Snapshot actions

| Action | Arguments |
|--------|-----------|
| `rudel_before_recovery_point_create` | `$context` |
| `rudel_after_recovery_point_create` | `$context`, `$meta` |
| `rudel_before_recovery_point_restore` | `$context` |
| `rudel_after_recovery_point_restore` | `$context` |
| `rudel_before_recovery_point_delete` | `$context` |
| `rudel_after_recovery_point_delete` | `$context` |

`$context` holds `environment`, `kind` (`snapshot`), `name` and `path`. Each of these also fires under a `snapshot` alias, for example `rudel_after_snapshot_create`, and failures fire `rudel_failed_recovery_point_{create|restore|delete}` and `rudel_failed_snapshot_{create|restore|delete}` with `$context` and the error. Those aliases are not in the catalog.

## Cleanup actions

| Action | Arguments |
|--------|-----------|
| `rudel_before_environment_cleanup` | `$options` |
| `rudel_after_environment_cleanup` | `$result`, `$options` |
| `rudel_before_environment_cleanup_merged` | `$options` |
| `rudel_after_environment_cleanup_merged` | `$result`, `$options` |

`$result` holds `removed`, `skipped`, `errors` and `reasons`.

## Git push actions

| Action | Arguments |
|--------|-----------|
| `rudel_before_environment_push` | `$context` |
| `rudel_after_environment_push` | `$sha`, `$context` |
| `rudel_environment_push_failed` | `$context`, `$error` |

These fire for `Rudel::push()`. `$context` holds `environment`, `remote`, `message` and `subdir`. The `wp rudel push` command pushes directly and does not fire them.

## Automation actions

| Action | Arguments |
|--------|-----------|
| `rudel_before_automation_cleanup` | `$config` |
| `rudel_after_automation_cleanup` | `$result`, `$config` |
| `rudel_after_automation_expiring_environments` | `$result`, `$config` |

`$config` is the full `rudel_config` array. See [Automation](/docs/features/automation).

## Filters

| Filter | Arguments |
|--------|-----------|
| `rudel_environment_create_options` | `$options`, `$name`, `$manager` |
| `rudel_environment_clone_source` | `$clone_source`, `$host_url`, `$db_cloned`, `$themes_cloned`, `$plugins_cloned`, `$uploads_cloned`, `$extra` |
| `rudel_environment_cleanup_options` | `$options`, `$repository` |
| `rudel_environment_cleanup_merged_options` | `$options`, `$repository` |
| `rudel_environment_db_dropin_contents` | `$contents`, `$context` |

`rudel_environment_create_options` can change the options of any create call before Rudel acts on them. `rudel_environment_clone_source` filters the clone metadata stored on a sandbox created from the host. `rudel_environment_db_dropin_contents` applies only to the `db.php` drop-in that multisite-subsite environments use; overlay sandboxes, the only kind current versions create, never generate one.

Filters are intended for policy adjustment, not for replacing Rudel's storage model. Runtime identity, table prefixes, and worktree metadata remain DB-backed Rudel state.
