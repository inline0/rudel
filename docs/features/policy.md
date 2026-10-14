---
title: "Policy"
description: "Stewardship, expiry, and lineage metadata for sandboxes."
path: "features/policy"
order: 70
section: "Features"
meta_title: "Policy"
meta_description: "Stewardship, expiry, and lineage metadata for sandboxes."
---

# Policy

Rudel sandboxes can carry metadata that explains who owns them, why they exist, when they should expire, and where they came from.

## Ownership

```bash
wp rudel create \
  --name=qa-run \
  --owner=dennis \
  --labels=qa,checkout \
  --purpose="Reproduce payment bug"
```

Metadata is stored in the environment record and can be updated later:

```bash
wp rudel update qa-run-3be7 --owner=agent --labels=agent,checkout
```

## Expiry

```bash
wp rudel create --name=temp-run --ttl-days=2
```

On `create`, `--ttl-days` counts from creation; on `update`, from now. Set `default_ttl_days` in `rudel_config` to give every new sandbox an expiry. Or set an explicit timestamp:

```bash
wp rudel update temp-run-3be7 --expires-at=2026-06-10T12:00:00+00:00
```

Expired sandboxes are eligible for cleanup unless they are protected. Remove an expiry with `wp rudel update <id> --clear-expiry`.

## Protection

```bash
wp rudel update qa-run-3be7 --protected
```

Protected sandboxes are skipped by cleanup, both manual and scheduled. Protection does not block `wp rudel destroy` or `Rudel::destroy()`.

## Lineage

When a sandbox is cloned from another sandbox, Rudel records source metadata in the new environment record.

That lineage is informational. It helps operators understand how a sandbox was created, but it is not a second source of truth.
