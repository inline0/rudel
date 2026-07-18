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
wp rudel snapshot alpha-1234 --name=before-test
```

Restore a snapshot:

```bash
wp rudel restore alpha-1234 --snapshot=before-test --force
```

Snapshots are for sandbox recovery, not for runtime identity. The environment record remains the source of truth for the sandbox.

## What snapshots capture

- sandbox WordPress tables
- managed active theme files
- snapshot metadata

## What snapshots do not capture

- host WordPress core
- shared plugin files
- shared uploads
- shared users
