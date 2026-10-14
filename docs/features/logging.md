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

For a selected sandbox request, the bootstrap defines `WP_DEBUG` as `true`, `WP_DEBUG_LOG` as the sandbox `debug.log` and `WP_DEBUG_DISPLAY` as `false`, so errors go to a per-sandbox file instead of the browser.

Each of these is defined only if `wp-config.php` has not defined it already, and the bootstrap line sits directly above the `wp-settings.php` line, after the rest of `wp-config.php`. A standard `wp-config.php` defines `WP_DEBUG` as `false`, so on such an install sandbox requests do not log to `debug.log` ([issue #4](https://github.com/inline0/rudel/issues/4)). To get per-sandbox logs there, remove the `WP_DEBUG`, `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` definitions from `wp-config.php`. WordPress then keeps debugging off for host requests, and the bootstrap turns logging on for sandbox requests.

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

Sandbox requests block outbound email. When `WP_DEBUG_LOG` is defined, each blocked message is logged with its recipient and subject through `error_log()`. It reaches the sandbox `debug.log` only when debug logging is active as described above; otherwise it goes to PHP's default error log.
