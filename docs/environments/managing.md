---
title: "Managing"
description: "Inspect, update, and remove Rudel sandboxes."
path: "environments/managing"
order: 40
section: "Environments"
meta_title: "Managing"
meta_description: "Inspect, update, and remove Rudel sandboxes."
---

# Managing

## List sandboxes

```bash
wp rudel list
```

Use `--format=json` when another tool needs structured output.

## Inspect one sandbox

```bash
wp rudel info alpha-1234
```

The record includes ID, path, status, table prefix, lifecycle metadata, timestamps, clone metadata, and worktree metadata.

## Update metadata

```bash
wp rudel update alpha-1234 \
  --owner=dennis \
  --labels=qa,agent \
  --purpose="Regression run" \
  --ttl-days=3
```

Metadata updates do not rewrite sandbox site content.

## Protect a sandbox

```bash
wp rudel update alpha-1234 --protected
```

Protected sandboxes are skipped by normal cleanup.

## Destroy a sandbox

```bash
wp rudel destroy alpha-1234
```

Protected sandboxes require:

```bash
wp rudel destroy alpha-1234 --force
```

Destroying a sandbox drops its cloned WordPress tables, removes managed files, cleans up worktree records, and deletes the Rudel environment record.
