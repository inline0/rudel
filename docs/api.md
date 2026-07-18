---
title: "API"
description: "Programmatic Rudel APIs."
path: "api"
order: 170
section: "Reference"
meta_title: "API"
meta_description: "Programmatic Rudel APIs."
---

# API

Use `Rudel\Rudel` when calling Rudel from WordPress code.

```php
use Rudel\Rudel;
```

## Runtime context

### `Rudel::is_active()`

Returns `true` when the current request is inside a Rudel sandbox.

### `Rudel::environment_id()`

Returns the current sandbox ID or `null`.

### `Rudel::environment()`

Returns the current `Rudel\Environment` object or `null`.

### `Rudel::context()`

Returns request-level Rudel context.

```php
$context = Rudel::context();
```

Example keys:

```php
[
    'active'         => true,
    'environment_id' => 'alpha-1234',
    'environment'    => [/* environment array */],
    'host_url'       => 'http://localhost:8000',
]
```

## Standalone initialization

Standalone mode is for metadata access outside a live WordPress request.

```php
Rudel::init( [
    'db' => [
        'host'     => '127.0.0.1',
        'database' => 'wordpress',
        'username' => 'root',
        'password' => 'root',
        'prefix'   => 'wp_',
    ],
    'context' => [
        'environments_dir' => '/var/www/html/wp-content/rudel-environments',
    ],
] );
```

Standalone mode can list and inspect Rudel records. Creating, destroying, bootstrapping, and cloning require WordPress runtime services.

## Environment records

### `Rudel::environments( array $filters = [] )`

Lists sandbox records.

```php
$environments = Rudel::environments( [
    'status' => 'active',
] );
```

### `Rudel::environment_by_id( string $id )`

Loads one sandbox by ID.

```php
$environment = Rudel::environment_by_id( 'alpha-1234' );
```

### `Rudel::create( string $name, array $options = [] )`

Creates a sandbox.

```php
$sandbox = Rudel::create( 'alpha', [
    'theme'    => 'twentytwentyfour',
    'owner'    => 'dennis',
    'labels'   => [ 'qa', 'agent' ],
    'ttl_days' => 7,
] );
```

Supported options include:

- `theme`
- `clone_from`
- `owner`
- `labels`
- `purpose`
- `protected`
- `ttl_days`
- `expires_at`

### `Rudel::update( string $id, array $changes )`

Updates lifecycle metadata for a sandbox.

### `Rudel::destroy( string $id, bool $force = false )`

Destroys a sandbox and its managed runtime state.

### `Rudel::cleanup( array $options = [] )`

Runs cleanup policy.

## Snapshots

### `Rudel::snapshot( string $id, string $name )`

Creates a snapshot.

### `Rudel::restore( string $id, string $snapshot, array $options = [] )`

Restores a snapshot.

## Git

### `Rudel::push( string $id, array $options = [] )`

Pushes tracked worktree changes for a sandbox.
