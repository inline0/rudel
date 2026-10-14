---
title: "API"
description: "The public PHP API of Rudel: runtime context, sandbox lifecycle, snapshots, templates, Git push and standalone access."
path: "api"
order: 170
section: "Reference"
meta_title: "API"
meta_description: "The Rudel PHP API: runtime context, sandbox create, update and destroy, snapshots, templates, Git push and standalone registry access."
---

# API

`Rudel\Rudel` is a static facade. Every method on this page is a public static method of that class.

```php
use Rudel\Rudel;

if ( Rudel::is_sandbox() ) {
    error_log( 'Running in sandbox ' . Rudel::id() );
}
```

Lifecycle methods that clone or drop tables (`create()`, `create_from_git()`, `destroy()`, `cleanup()`, `cleanup_merged()`) need a live WordPress request with `$wpdb`. They throw a `RuntimeException` when called after `Rudel::init()` outside WordPress. See [Standalone Use](/docs/features/standalone-use).

## Runtime context

These methods describe the current request. Outside a sandbox they return `false` or `null`.

| Method | Returns |
|--------|---------|
| `is_sandbox(): bool` | `true` when the current request runs inside a sandbox |
| `id(): ?string` | the current sandbox ID |
| `path(): ?string` | the current sandbox directory |
| `engine(): ?string` | `overlay` inside a sandbox |
| `table_prefix(): ?string` | the sandbox table prefix, for example `wp_b65338d_` |
| `url(): ?string` | the sandbox URL; overlay sandboxes share the host URL |
| `exit_url(): string` | the host URL |
| `is_email_disabled(): bool` | `true` when outbound mail is blocked for the request |
| `log_path(): ?string` | `{sandbox directory}/debug.log` |
| `version(): ?string` | the installed Rudel version |
| `cli_command(): string` | the WP-CLI root command, `rudel` unless `RUDEL_CLI_COMMAND` is set |

### `Rudel::context(): array`

Returns all of the above in one array:

```php
[
    'is_sandbox'     => true,
    'id'             => 'alpha-3be7',
    'path'           => '/var/www/html/wp-content/rudel-environments/alpha-3be7',
    'engine'         => 'overlay',
    'table_prefix'   => 'wp_b65338d_',
    'url'            => 'http://localhost/',
    'exit_url'       => 'http://localhost/',
    'email_disabled' => true,
    'log_path'       => '/var/www/html/wp-content/rudel-environments/alpha-3be7/debug.log',
    'version'        => '0.10.0',
    'cli_command'    => 'rudel',
]
```

### `Rudel::status(): array`

Returns the same runtime summary as `wp rudel status` as an array: bootstrap state, current sandbox, sandboxes directory and count, the `rudel_config` values and whether automation is scheduled.

## Sandbox records

Sandboxes are returned as `Rudel\Environment` objects. Useful read-only properties include `id`, `name`, `path`, `created_at`, `status`, `template`, `owner`, `labels`, `purpose`, `expires_at`, `last_used_at`, `theme_slug`, `table_prefix` and `clone_source`. Methods include `is_protected()`, `get_url()`, `get_table_prefix()`, `get_size()` and `to_array()`.

### `Rudel::all(): array`

Returns every sandbox as an array of `Environment` objects. It takes no filters.

```php
foreach ( Rudel::all() as $sandbox ) {
    echo $sandbox->id . ' ' . $sandbox->status . "\n";
}
```

### `Rudel::get( string $id ): ?Environment`

Loads one sandbox by ID, or returns `null`.

```php
$sandbox = Rudel::get( 'alpha-3be7' );
```

### `Rudel::environments_dir(): string`

Returns the directory that holds sandbox directories.

## Lifecycle

### `Rudel::create( string $name, array $options = [] ): Environment`

Creates a sandbox and returns it. The ID is generated from the name plus four hex characters.

```php
$sandbox = Rudel::create( 'alpha', [
    'theme'    => 'twentytwentyfour',
    'owner'    => 'dennis',
    'labels'   => [ 'qa', 'agent' ],
    'ttl_days' => 7,
] );
```

Options:

| Key | Meaning |
|-----|---------|
| `theme` | theme slug to copy into the sandbox; defaults to the host's active theme |
| `clone_from` | ID of an existing sandbox to clone instead of the host |
| `owner`, `purpose` | free-text metadata |
| `labels` | array or comma-separated string |
| `protected` | `true` to exclude the sandbox from cleanup |
| `ttl_days` | expiry relative to creation time |
| `expires_at` | explicit expiry timestamp |
| `template` | a label stored on the record; it does not load a saved template |
| `site_options` | option names and scalar values written to the sandbox options table |
| `skip_limits` | `true` to bypass `max_sandboxes` and `max_disk_mb` |

### `Rudel::create_from_git( string $remote_url, array $options = [] ): array`

Creates a sandbox, then clones `$remote_url` into it. Accepts the `create()` options plus `name` (defaults to the repository name) and `type` (`theme` or `plugin`). Returns `[ 'environment' => Environment, 'git' => [...] ]`. A failed clone does not roll back the sandbox; the error is returned in `git.error`. See [Git](/docs/features/git).

### `Rudel::update( string $id, array $changes ): Environment`

Updates lifecycle metadata and returns the refreshed sandbox. Accepted keys include `owner`, `labels`, `purpose`, `protected`, `ttl_days` (relative to now), `expires_at`, `clear_expiry`, `name` and `site_options`.

```php
Rudel::update( 'alpha-3be7', [ 'protected' => true, 'ttl_days' => 3 ] );
```

### `Rudel::destroy( string $id ): bool`

Drops the sandbox tables, removes its directory and linked worktrees, and deletes its record. Returns `false` when the ID does not exist. It does not check protection: protected sandboxes are destroyed too.

### `Rudel::cleanup( array $options = [] ): array`

Runs the expiry, age and idle policy. Options: `dry_run` (bool), `max_age_days` and `max_idle_days` (override `rudel_config` when non-zero). Returns `removed`, `skipped`, `errors` and `reasons` (ID to `expired`, `age`, `idle` or `protected`). See [Cleanup](/docs/features/cleanup).

### `Rudel::cleanup_merged( array $options = [] ): array`

Removes sandboxes whose linked Git worktree branches are merged into the repository's default branch. Option: `dry_run`.

### `Rudel::touch_current_environment(): void`

Records activity (`last_used_at`) for the current sandbox. Rudel calls it on every sandbox request.

## Logs

### `Rudel::read_log( string $id, int $lines = 50 ): array`

Returns the last lines of the sandbox `debug.log` as `path`, `exists`, `empty`, `total_lines` and `lines`.

### `Rudel::clear_log( string $id ): array`

Empties the sandbox `debug.log` and returns `path`, `existed` and `cleared`.

## Snapshots

### `Rudel::snapshot( string $id, string $name ): array`

Creates a snapshot and returns its metadata (`name`, `created_at`, `sandbox_id`).

### `Rudel::restore( string $id, string $name ): void`

Restores a snapshot. With the default `auto_snapshot_before_restore` setting, Rudel first saves a `pre-restore-...` snapshot.

### `Rudel::snapshots( string $id ): array`

Lists snapshot metadata, newest first. See [Snapshots](/docs/features/snapshots).

## Templates

- `Rudel::save_template( string $id, string $name, string $description = '' ): array`
- `Rudel::templates(): array`
- `Rudel::delete_template( string $name ): bool`

Saved templates are file copies only. No API or command creates a sandbox from one. See [Templates](/docs/features/templates).

## Git

### `Rudel::push( string $id, string $remote_url = '', string $message = 'Update from Rudel', string $subdir = '' ): ?string`

Commits and pushes a checkout inside the sandbox directory to the branch `rudel/{id}`. `$remote_url` and `$subdir` default to the values stored on the sandbox. Returns the commit SHA, or `null` when nothing changed.

## Automation

- `Rudel::run_automation(): array` runs the scheduled automation tasks now.
- `Rudel::run_scheduled_cleanup(): array` does the same.

See [Automation](/docs/features/automation).

## Hooks and command catalog

- `Rudel::hooks(): array` returns the documented hook catalog, keyed by hook name. See [Hooks](/docs/features/hooks).
- `Rudel::cli_command_map(): array` returns a serializable description of every `wp rudel` command and the PHP method behind it.
- `Rudel::resolve_cli_command( $path, array $args = [], array $assoc_args = [] ): array` turns one parsed command into an execution plan without running it.

```php
$plan = Rudel::resolve_cli_command( 'wp rudel destroy', [ 'alpha-3be7' ] );
// $plan['callable']           => 'Rudel\Rudel::destroy'
// $plan['arguments']          => [ 'alpha-3be7' ]
// $plan['needs_confirmation'] => true
```

Most plans use the `php` transport. `logs --follow` resolves to a `shell` plan running `tail -f`.

## Standalone initialization

### `Rudel::init( Connection $connection, array $context = [] ): void`

Points the facade at a direct MySQL connection for use outside WordPress. `$context` accepts `environments_dir`.

```php
use Rudel\Connection;
use Rudel\Rudel;

Rudel::init(
    new Connection( '127.0.0.1', 'wordpress', 'root', 'root', 'wp_' ),
    [ 'environments_dir' => '/var/www/html/wp-content/rudel-environments' ]
);
```

- `Rudel::ensure_schema(): void` creates the Rudel tables for the active connection.
- `Rudel::reset(): void` clears the standalone connection and cached state.
