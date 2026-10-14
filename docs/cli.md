---
title: "CLI"
description: "Every wp rudel command with the options it accepts."
path: "cli"
order: 160
section: "Reference"
meta_title: "CLI"
meta_description: "Reference for every wp rudel command: create, list, info, update, destroy, status, cleanup, logs, snapshot, restore, template and push, with their options."
---

# CLI

Rudel registers its commands under `wp rudel`. WP-CLI rejects an option that a command does not list here with `unknown --<name> parameter`.

You can rename the root command for embedded use by defining `RUDEL_CLI_COMMAND` in `wp-config.php` before the plugin loads.

```php
define( 'RUDEL_CLI_COMMAND', 'sandbox' );
```

That changes `wp rudel create` into `wp sandbox create`. The command behavior does not change.

Commands run against the host by default. To run any other WP-CLI command inside a sandbox, set `RUDEL_ENVIRONMENT`:

```bash
RUDEL_ENVIRONMENT=alpha-3be7 wp option get blogname
```

## create

```bash
wp rudel create [--name=<name>] [--theme=<slug>] [--clone-from=<id>] [--owner=<owner>] [--labels=<labels>] [--purpose=<purpose>] [--protected] [--ttl-days=<days>] [--expires-at=<timestamp>] [--git=<remote>] [--type=<theme|plugin>] [--template=<label>]
```

Creates a sandbox and prints its ID.

| Option | Meaning |
|--------|---------|
| `--name` | Human-readable name. The ID is the name plus four hex characters. Defaults to the repository name with `--git`, otherwise `sandbox`. |
| `--theme` | Theme to copy into the sandbox. Must be installed on the host. Defaults to the host's active theme, or to the source sandbox's theme with `--clone-from`. |
| `--clone-from` | Clone tables and the theme from an existing sandbox instead of the host. |
| `--owner`, `--labels`, `--purpose` | Metadata. Labels are comma-separated. |
| `--protected` | Exclude the sandbox from cleanup. |
| `--ttl-days` | Expiry relative to creation time. |
| `--expires-at` | Explicit expiry timestamp. |
| `--git` | Clone a remote repository into the sandbox directory. See [Git](/docs/features/git). |
| `--type` | Where `--git` checks out: `theme` (default) or `plugin`. |
| `--template` | A label stored on the record, default `blank`. It does not load a saved template. |

`--clone-db`, `--clone-themes`, `--clone-plugins`, `--clone-uploads`, `--clone-all`, `--shared-plugins` and `--shared-uploads` are deprecated. They only change the progress messages: every sandbox clones the tables, copies one theme and shares plugins and uploads.

The command ends with a hint to `cd` into the sandbox directory and run `wp post list`. That runs against the host, because the `wp-cli.yml` in the sandbox directory points at the host URL. Use `RUDEL_ENVIRONMENT` instead.

## list

```bash
wp rudel list [--format=<table|json|csv|yaml|count>]
```

Lists sandboxes with ID, name, owner, protection, expiry, status, template label, creation time and directory size.

## info

```bash
wp rudel info <id> [--format=<table|json|yaml>]
```

Shows one sandbox record, including table prefix, theme, labels, clone metadata, URL and content path.

## update

```bash
wp rudel update <id> [--owner=<owner>] [--labels=<labels>] [--purpose=<purpose>] [--protected] [--unprotected] [--ttl-days=<days>] [--expires-at=<timestamp>] [--clear-expiry]
```

Updates metadata. `--ttl-days` counts from now. It does not change sandbox site content.

## destroy

```bash
wp rudel destroy <id> [--force]
```

Drops the sandbox tables, removes its directory and linked worktrees, and deletes its record. The command asks for confirmation; `--force` skips the prompt. Protection does not block `destroy`: a protected sandbox is destroyed like any other.

## status

```bash
wp rudel status
```

Prints a table with the bootstrap state, current sandbox, sandboxes directory, sandbox count, the `rudel_config` values and whether automation is scheduled. It always prints a table and ignores `--format`.

## cleanup

```bash
wp rudel cleanup [--dry-run] [--max-age-days=<days>] [--max-idle-days=<days>] [--merged]
```

Without `--merged`, removes unprotected sandboxes whose expiry has passed, or that are older than the age limit or idle longer than the idle limit. `--max-age-days` and `--max-idle-days` override `rudel_config` for this run. With `--merged`, removes sandboxes whose linked Git worktree branches are merged. Protected sandboxes are always skipped. See [Cleanup](/docs/features/cleanup).

## logs

```bash
wp rudel logs <id> [--lines=<lines>] [--follow] [--clear]
```

Prints the last lines of the sandbox `debug.log` (default 50). `--follow` runs `tail -f`; `--clear` empties the file. See [Error Logging](/docs/features/logging).

## snapshot

```bash
wp rudel snapshot <id> --name=<name>
```

Creates a named snapshot.

## restore

```bash
wp rudel restore <id> --snapshot=<name> [--force]
```

Restores a snapshot. The command asks for confirmation; `--force` skips the prompt. See [Snapshots](/docs/features/snapshots).

## template

```bash
wp rudel template save <id> --name=<name> [--description=<description>]
wp rudel template list [--format=<table|json|csv|yaml|count>]
wp rudel template delete <name> [--force]
```

Saves, lists and deletes template copies. No command creates a sandbox from a saved template. See [Templates](/docs/features/templates).

## push

```bash
wp rudel push <id> [--git=<remote>] [--dir=<dir>] [--message=<message>]
```

Commits and pushes a checkout inside the sandbox directory to the branch `rudel/<id>`.

| Option | Meaning |
|--------|---------|
| `--git` | Remote URL. Needed only when none is stored; remembered after the first successful push. |
| `--dir` | Directory relative to the sandbox directory, for example `themes/my-theme`. Defaults to the stored checkout directory, otherwise the whole sandbox directory. |
| `--message` | Commit message, default `Update from Rudel sandbox`. |

See [Git](/docs/features/git).
