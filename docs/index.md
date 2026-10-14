---
title: "Introduction"
description: "An introduction to Rudel: request-selected sandboxes clone the WordPress tables, users included, and the active theme; core, plugins and uploads stay shared."
path: "."
order: 10
meta_title: "Introduction"
meta_description: "An introduction to Rudel: request-selected sandboxes clone the WordPress tables, users included, and the active theme; core, plugins and uploads stay shared."
---

# Introduction
Rudel creates disposable WordPress sandboxes inside one host WordPress installation.

Each sandbox is selected per request. The selected request uses the sandbox's cloned WordPress tables and its copy of the active theme, while WordPress core, plugin files and uploads stay shared with the host.

## What a sandbox gets

- a copy of the host's tables, including users and user meta, under a generated table prefix
- a copy of one theme (plus its parent theme for a child theme) in the sandbox directory
- lifecycle metadata in host WordPress MySQL tables

Users are copied when the sandbox is created. After that, users and passwords in the sandbox and on the host change independently.

## What stays shared

- WordPress core files
- plugin files
- uploaded media files

That boundary is intentional. Rudel focuses on fast, request-selected sandboxes for agents, QA, experiments, and risky change work without requiring multisite or a second WordPress install.

## How a request selects a sandbox

An HTTP request selects a sandbox with the `X-Rudel-Environment` header or the `rudel_environment` cookie; a WP-CLI command uses the `RUDEL_ENVIRONMENT` environment variable. Rudel does not authenticate the selector: any client that sends a valid sandbox ID gets that sandbox. See [Isolation](/docs/environments/isolation).

## Source of truth

Rudel runtime records live in the host WordPress database:

- `wp_rudel_environments`
- `wp_rudel_worktrees`

Settings live in the `rudel_config` option. There is no runtime JSON config and no SQLite metadata store.

## Start here

- [Getting Started](/docs/getting-started)
- [CLI](/docs/cli)
- [API](/docs/api)
- [Configuration](/docs/configuration)
- [Runtime State](/docs/features/runtime-state)
- [Isolation](/docs/environments/isolation)
