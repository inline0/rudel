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

For a selected sandbox request, Rudel configures PHP logging after WordPress's debug setup: all PHP error levels, `log_errors` enabled, `display_errors` disabled, and `error_log` set to the sandbox `debug.log`.

This works with a standard `wp-config.php`, including one that defines `WP_DEBUG` or `WP_DEBUG_LOG` as `false`. Existing WordPress constants keep their values; WordPress features that explicitly consult `WP_DEBUG` still follow that setting. The runtime loads for selected requests independently of the sandbox's active plugin list.

## Viewing logs

```bash
wp rudel logs alpha-3be7
wp rudel logs alpha-3be7 --lines=200
wp rudel logs alpha-3be7 --follow
```

The default is the last 50 lines. `--follow` runs `tail -f` on the file.

## Clearing logs

```bash
wp rudel logs alpha-3be7 --clear
```

## Log location

The log file lives in the sandbox directory:

```text
wp-content/rudel-environments/{id}/debug.log
```

That directory is inside `wp-content`, and web servers serve it like any other file there. Block web access to `*.log` files and to the `snapshots` directories under `rudel-environments` on any host reachable by others ([issue #9](https://github.com/inline0/rudel/issues/9)).

## Blocked email

Sandbox requests block outbound email. Each blocked message is logged with its recipient and subject through `error_log()` to the selected sandbox log.
