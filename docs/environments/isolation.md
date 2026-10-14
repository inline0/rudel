---
title: "Isolation"
description: "What Rudel isolates and what remains shared."
path: "environments/isolation"
order: 50
section: "Environments"
meta_title: "Isolation"
meta_description: "What Rudel isolates and what remains shared."
---

# Isolation

Rudel is a request-selected overlay system. A selected request uses sandbox database tables and sandbox theme files while still running through the host WordPress installation.

## Isolated per sandbox

### WordPress tables

Each sandbox gets a generated table prefix: the host prefix plus the first seven hex characters of the MD5 of the sandbox ID.

```text
wp_b65338d_options
wp_b65338d_posts
wp_b65338d_users
```

Rudel switches the WordPress table prefix before WordPress finishes booting, so every query in a sandbox request reads and writes the sandbox copies.

### Users

The `users` and `usermeta` tables are cloned with the rest. A sandbox starts with the host's users and passwords at creation time; after that, users created, changed or deleted in the sandbox do not affect the host, and host changes do not reach the sandbox.

### Active theme files

The selected theme is copied into the sandbox directory:

```text
wp-content/rudel-environments/{environment_id}/themes/{theme_slug}
```

If the theme is a child theme, Rudel also copies the parent theme so the template relationship stays valid. A sandbox always serves the theme recorded at creation; switching themes inside the sandbox has no effect.

## Shared with the host

- WordPress core
- plugin files
- uploaded media files

A plugin installed or updated on the host is installed or updated for every sandbox. Which plugins are active is stored in the options table, so activation state is per sandbox. Uploads made inside a sandbox land in the host uploads directory. This keeps sandbox creation fast and avoids replacing `WP_CONTENT_DIR` globally.

The bootstrap loads Rudel's runtime during `muplugins_loaded` for selected sandbox requests. Mail blocking and theme filters therefore remain active even when that sandbox deactivates Rudel in its plugin list. Sandbox deactivation keeps the shared bootstrap and host cleanup schedule installed; deactivation on the host still removes them.

## Selecting a sandbox

HTTP requests select a sandbox through:

- the `X-Rudel-Environment` header (legacy alias `X-Rudel-Sandbox`)
- the `rudel_environment` cookie (legacy alias `rudel_sandbox`)

The Sandbox item in the admin bar uses a nonce-protected exit link. Following it expires both `rudel_environment` and legacy `rudel_sandbox` selector cookies at the standard WordPress cookie paths, then redirects to the host. A request with an invalid nonce leaves the selection in place.

WP-CLI commands select a sandbox through:

- the `RUDEL_ENVIRONMENT` environment variable
- a `--url` whose host starts with the sandbox ID, such as `--url=http://alpha-3be7.example.test`

The selector is not authenticated. Any client that can reach the site and sends a valid sandbox ID gets that sandbox, and sandbox IDs (name plus four hex characters) are easy to guess. Do not treat sandbox selection as an access-control boundary; protect a public host at the web server or network level.

## Request defaults

For a selected request, Rudel:

- blocks outbound email through a `pre_wp_mail` filter
- sets `WP_TEMP_DIR` to the sandbox `tmp` directory
- sets `WP_CACHE_KEY_SALT` to a sandbox-specific value so persistent object caches keep sandbox keys apart
- routes PHP errors to the sandbox `debug.log` with browser display disabled and derives effective authentication salts from the environment ID and WordPress's secret salt

Existing WordPress constants retain their configured values. The `salt` filter scopes the effective signing secret to the selected environment, so host and sibling authentication cookies are rejected. PHP logging is configured after WordPress's debug setup, so host debug constants do not prevent the sandbox log.

## Runtime metadata

Rudel runtime metadata stays in host WordPress MySQL tables:

```text
wp_rudel_environments
wp_rudel_worktrees
```

These tables are Rudel's source of truth. Environment directories contain managed files and artifacts, not runtime registry state.
