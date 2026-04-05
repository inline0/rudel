# Rudel Worklist

## Current architecture

- Rudel runs on subdomain multisite only.
- Every sandbox and app is a real multisite site.
- Real site URLs are the only supported browser/runtime URLs.
- Runtime metadata lives in WordPress tables.

## Remaining product work

- improve network-user UX so app and sandbox membership reads more clearly in wp-admin
- tighten custom-domain workflows for long-lived apps
- expand worktree ergonomics for theme and plugin code isolation
- continue reducing duplicated `wp-content` where native multisite behavior already provides the right boundary
- keep docs and e2e coverage aligned with the multisite contract
