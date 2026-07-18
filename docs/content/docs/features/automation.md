---
title: "Automation"
description: "Scheduled cleanup and expiry reporting."
path: "features/automation"
order: 90
section: "Features"
meta_title: "Automation"
meta_description: "Scheduled cleanup and expiry reporting."
---

# Automation

Rudel's automation layer keeps disposable sandboxes from accumulating forever.

Automation can:

- run cleanup policy
- report expired sandboxes
- report sandboxes close to expiry

## Configuration

Automation settings live in Rudel's DB-backed config store.

| Key | Purpose | Default |
|-----|---------|---------|
| `auto_cleanup_enabled` | Run scheduled cleanup | `0` |
| `auto_cleanup_merged` | Include merged Git branches | `0` |
| `auto_cleanup_expired` | Include expired sandboxes | `1` |
| `expiry_warning_days` | Warn when expiry is within N days | `2` |

## WP-Cron

When enabled, Rudel schedules an hourly WP-Cron event.

```bash
wp rudel automation enable
wp rudel automation status
```

Use manual cleanup for deterministic local or CI runs:

```bash
wp rudel cleanup --dry-run
wp rudel cleanup --force
```
