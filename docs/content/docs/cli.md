---
title: "CLI"
description: "The Rudel WP-CLI command surface."
path: "cli"
order: 160
section: "Reference"
meta_title: "CLI"
meta_description: "The Rudel WP-CLI command surface."
---

# CLI

Rudel publishes its command surface under `wp rudel` by default.

You can rename the root command for embedded use by defining `RUDEL_CLI_COMMAND` before the plugin registers WP-CLI commands.

```php
define( 'RUDEL_CLI_COMMAND', 'sandbox' );
```

That changes `wp rudel create` into `wp sandbox create`. The command behavior does not change.

## Status

```bash
wp rudel status [--format=<format>]
```

Shows runtime health, table names, environment counts, and bootstrap state.

## Create

```bash
wp rudel create --name=<name> [--theme=<slug>] [--clone-from=<id>] [--owner=<owner>] [--labels=<labels>] [--purpose=<purpose>] [--protected] [--ttl-days=<days>] [--expires-at=<timestamp>]
```

Creates a sandbox. When `--clone-from` is provided, Rudel clones from another sandbox instead of the host site.

## List

```bash
wp rudel list [--format=<format>]
```

Lists sandbox records.

## Info

```bash
wp rudel info <id> [--format=<format>]
```

Shows one sandbox record, including lifecycle metadata and worktree metadata when present.

## Update

```bash
wp rudel update <id> [--owner=<owner>] [--labels=<labels>] [--purpose=<purpose>] [--protected] [--unprotected] [--ttl-days=<days>] [--expires-at=<timestamp>] [--clear-expiry]
```

Updates lifecycle metadata. It does not rewrite sandbox site content.

## Destroy

```bash
wp rudel destroy <id> [--force]
```

Destroys a sandbox, drops its cloned tables, removes managed files, and deletes its runtime record.

Protected sandboxes require `--force`.

## Cleanup

```bash
wp rudel cleanup [--force] [--dry-run] [--merged] [--expired] [--format=<format>]
```

Removes stale sandboxes according to Rudel's cleanup policy.

## Logs

```bash
wp rudel logs <id> [--tail=<lines>]
```

Prints a sandbox debug log.

## Snapshots

```bash
wp rudel snapshot <id> --name=<name>
wp rudel restore <id> --snapshot=<name> [--force]
```

Snapshots are recovery points for sandbox table state and managed theme files.

## Templates

```bash
wp rudel template save <id> --name=<name>
wp rudel template list
wp rudel template delete <name>
```

Templates let you reuse a known sandbox shape for future sandboxes.

## Git Push

```bash
wp rudel push <id> [--remote=<remote>] [--branch=<branch>] [--message=<message>]
```

Pushes tracked theme worktree changes through Rudel's PHP-native Git integration.
