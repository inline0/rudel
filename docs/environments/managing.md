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
wp rudel info alpha-3be7
```

The record includes ID, name, path, status, engine, table prefix, theme, protection, labels, last activity, clone metadata, size, URL and content path.

## Update metadata

```bash
wp rudel update alpha-3be7 \
  --owner=dennis \
  --labels=qa,agent \
  --purpose="Regression run" \
  --ttl-days=3
```

Metadata updates do not rewrite sandbox site content. `--ttl-days` sets the expiry relative to now; `--clear-expiry` removes it.

## Protect a sandbox

```bash
wp rudel update alpha-3be7 --protected
```

Protected sandboxes are skipped by cleanup, both manual and scheduled. Remove protection with `--unprotected`.

## Destroy a sandbox

```bash
wp rudel destroy alpha-3be7
```

The command asks for confirmation. Pass `--force` to skip the prompt in scripts:

```bash
wp rudel destroy alpha-3be7 --force
```

Protection does not apply here: `destroy` removes protected sandboxes too.

Destroying a sandbox drops every table with its prefix (including snapshot tables), removes linked worktrees and the sandbox directory, and deletes the Rudel environment record.
