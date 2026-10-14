---
title: "Standalone Use"
description: "Read Rudel metadata outside a WordPress request."
path: "features/standalone-use"
order: 150
section: "Features"
meta_title: "Standalone Use"
meta_description: "Read Rudel metadata outside a WordPress request."
---

# Standalone Use

Standalone mode lets external PHP code inspect Rudel's registry with a direct MySQL connection, without loading WordPress.

It is intended for:

- listing sandboxes
- loading one sandbox by ID
- reading lifecycle metadata, clone metadata and table prefixes

It is not intended for:

- creating sandboxes
- destroying sandboxes
- running cleanup
- cloning WordPress tables
- routing live requests

`Rudel::create()`, `create_from_git()`, `destroy()`, `cleanup()` and `cleanup_merged()` throw a `RuntimeException` in standalone mode because they need WordPress runtime services.

## Initialize

Load Composer's autoloader, then pass a `Rudel\Connection` to `Rudel::init()`:

```php
use Rudel\Connection;
use Rudel\Rudel;

require __DIR__ . '/vendor/autoload.php';

Rudel::init(
    new Connection(
        '127.0.0.1',  // host, in DB_HOST format (host, host:port or a socket path)
        'wordpress',  // database
        'root',       // user
        'root',       // password
        'wp_'         // WordPress table prefix
    ),
    [ 'environments_dir' => '/var/www/html/wp-content/rudel-environments' ]
);
```

`Connection` takes two more optional arguments: a Rudel table prefix override and a driver (`auto`, `mysqli` or `pdo`). Without a driver argument it uses the `RUDEL_DB_DRIVER` constant, then `auto`, which prefers `pdo_mysql` outside WordPress and falls back to `mysqli`.

## Read records

```php
foreach ( Rudel::all() as $environment ) {
    echo $environment->id . ' ' . $environment->get_table_prefix() . "\n";
}

$environment = Rudel::get( 'alpha-3be7' );
```

Standalone reads use the same repository layer as WordPress runtime reads. `Rudel::reset()` clears the standalone connection.
