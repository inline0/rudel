---
title: "Snapshots"
description: "Recovery points for Rudel sandboxes."
path: "features/snapshots"
order: 110
section: "Features"
meta_title: "Snapshots"
meta_description: "Recovery points for Rudel sandboxes."
---

# Snapshots

Snapshots capture a sandbox recovery point.

```bash
wp rudel snapshot alpha-3be7 --name=before-test
```

Restore a snapshot:

```bash
wp rudel restore alpha-3be7 --snapshot=before-test
```

`restore` asks for confirmation; `--force` skips the prompt. Snapshot names are up to 64 letters, digits, dots, dashes or underscores, starting with a letter or digit.

Snapshots are for sandbox recovery, not for runtime identity. The environment record remains the source of truth for the sandbox.

## What snapshots capture

- every table with the sandbox prefix, users included, copied in the same database under `{sandbox_prefix}snap_{hash}_`, for example `wp_b65338d_snap_3ba676_options`
- the sandbox theme directory, copied to `snapshots/{name}/themes` in the sandbox directory
- metadata in the host Rudel artifacts table; older JSON metadata is migrated and removed
- local Git-created plugin overrides, when present

Snapshot files are protected from Apache requests by directory deny rules. On servers that do not read `.htaccess`, deny HTTP access to the private recovery directories in the server configuration.

A snapshot therefore costs a full copy of the sandbox tables in the host database plus a copy of the theme files.

## What snapshots do not capture

- host WordPress core
- shared plugin files
- shared uploads

## Restore

A restore drops the sandbox tables (keeping snapshot tables), copies the snapshot tables back, and replaces the sandbox theme directory and captured local plugin overrides with the saved copies.

By default Rudel first saves the current state as a snapshot named `pre-restore-{timestamp}-{hash}`, so a restore can be undone. Set `auto_snapshot_before_restore` to `0` in `rudel_config` to turn that off.

## Listing and removing

From PHP, `Rudel::snapshots( $id )` lists snapshots, newest first. Delete one with `wp rudel snapshot delete <id> --name=<name>` or `Rudel::delete_snapshot( $id, $name )`. Deletion removes its tables and directory while preserving the sandbox's live tables. `SnapshotManager::prune( $keep )` keeps the newest positive number of snapshots; a non-positive value leaves them unchanged.
