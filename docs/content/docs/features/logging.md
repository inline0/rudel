---
title: "Error Logging"
description: "Per-sandbox debug logs with a dedicated CLI viewer."
path: "features/logging"
order: 140
section: "Features"
meta_title: "Error Logging"
meta_description: "Per-sandbox debug logs with a dedicated CLI viewer."
---

# Error Logging

Sandboxes enable debug logging by default. Rudel sets `WP_DEBUG`, `WP_DEBUG_LOG`, and `WP_DEBUG_DISPLAY` for selected sandbox requests so errors are captured without leaking into browser output.

## Viewing logs

```bash
wp rudel logs my-sandbox-a1b2
wp rudel logs my-sandbox-a1b2 --lines=200
wp rudel logs my-sandbox-a1b2 --follow
```

## Clearing logs

```bash
wp rudel logs my-sandbox-a1b2 --clear
```

## Log location

The log file lives in the environment directory:

```text
{environment}/debug.log
```

Apps allow normal email delivery. Sandboxes block outbound email by default and log blocked messages to the sandbox log.
