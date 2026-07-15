---
title: "Rudel"
description: "Request-selected WordPress overlay environments for sandboxes."
path: "."
order: 0
section: "Documentation"
meta_title: "Rudel"
meta_description: "Request-selected WordPress overlay environments for sandboxes."
---

Rudel creates disposable WordPress sandboxes inside one host WordPress installation.

Each sandbox is selected per request. The selected request uses cloned WordPress tables and a copied active theme while the host WordPress core, plugins, uploads, and users remain shared by default.

## What Rudel isolates

- database content through a generated table prefix
- active theme files through a copied theme root
- request context through trusted headers, cookies, or CLI environment variables
- lifecycle metadata through host WordPress MySQL tables

## What Rudel does not isolate by default

- WordPress core files
- plugin files
- uploaded media files
- users

That boundary is intentional. Rudel focuses on fast, request-selected sandboxes for agents, QA, experiments, and risky change work without requiring multisite or a second WordPress install.

## Source of truth

Rudel runtime records live in the host WordPress database:

- `wp_rudel_environments`
- `wp_rudel_worktrees`

There is no runtime JSON config and no SQLite metadata store.

## Start here

- [Getting Started](/docs/getting-started)
- [CLI](/docs/cli)
- [API](/docs/api)
- [Runtime State](/docs/features/runtime-state)
- [Isolation](/docs/environments/isolation)
