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

Cleanup removes sandboxes that are no longer needed. Preview first:

```bash
wp rudel cleanup --dry-run
```

`--dry-run` lists what would be removed and why. Run it without the flag to remove them:

```bash
wp rudel cleanup
```

The command does not prompt.

## Policy

An unprotected sandbox is removed when any of these holds:

| Reason | Rule |
|--------|------|
| `expiry reached` | its `expires_at` is in the past |
| `age policy matched` | it was created more than `max_age_days` ago |
| `idle policy matched` | its last activity is more than `max_idle_days` ago |

`max_age_days` and `max_idle_days` come from `rudel_config` (both default to `0`, which disables the rule). Override them for one run:

```bash
wp rudel cleanup --max-age-days=30 --dry-run
wp rudel cleanup --max-idle-days=7
```

Last activity is updated by every request that selects the sandbox and by snapshots.

## Merged sandboxes

```bash
wp rudel cleanup --merged --dry-run
```

`--merged` runs a separate pass instead of the policy above. It removes a sandbox when every linked Git worktree recorded for it is on a branch that is merged into the repository's default branch, then deletes those branches. Linked worktrees come from cloning a sandbox whose theme directory is a Git repository (see [Git](/docs/features/git)). Sandboxes created with `--git` have no worktree records and are always skipped ([issue #8](https://github.com/inline0/rudel/issues/8)).

## Protected sandboxes

Both passes skip protected sandboxes. Remove protection with `wp rudel update <id> --unprotected` first if cleanup should consider them. `wp rudel destroy` ignores protection.

Removing a sandbox drops its tables, removes managed files and worktrees, and deletes the Rudel environment record. See [Automation](/docs/features/automation) to run cleanup on a schedule.
