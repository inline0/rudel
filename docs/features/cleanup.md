---
title: "Cleanup"
description: "Remove stale Rudel sandboxes safely."
path: "features/cleanup"
order: 80
section: "Features"
meta_title: "Cleanup"
meta_description: "Remove stale Rudel sandboxes safely."
---

# Cleanup

Cleanup removes sandboxes that are no longer needed.

```bash
wp rudel cleanup --dry-run
```

Use `--dry-run` first to see what would be removed.

```bash
wp rudel cleanup --force
```

## Expired sandboxes

Sandboxes with an `expires_at` timestamp in the past are cleanup candidates.

```bash
wp rudel cleanup --expired --dry-run
```

## Merged sandboxes

When Git metadata indicates a sandbox branch has already been merged, Rudel can include it in cleanup.

```bash
wp rudel cleanup --merged --dry-run
```

## Protected sandboxes

Protected sandboxes are skipped unless the operator passes `--force`.

Cleanup drops cloned tables, removes managed files, cleans worktree records, and deletes the Rudel environment record.
