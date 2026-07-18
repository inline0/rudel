---
title: "Hooks"
description: "Rudel lifecycle hooks for extensions."
path: "features/hooks"
order: 100
section: "Features"
meta_title: "Hooks"
meta_description: "Rudel lifecycle hooks for extensions."
---

# Hooks

Rudel exposes hooks around sandbox lifecycle events.

Common hook groups include:

- sandbox create, update, destroy
- snapshot and restore
- cleanup and expiry reporting
- Git push and worktree operations

## Example

```php
add_action(
    'rudel_after_environment_create',
    function ( \Rudel\Environment $environment ): void {
        error_log( 'Created Rudel environment ' . $environment->id );
    }
);
```

Hook payloads use Rudel models and arrays. Prefer the documented public API over reaching into command classes.

## Filters

Filters are intended for policy adjustment, not for replacing Rudel's storage model.

Runtime identity, table prefixes, and worktree metadata remain DB-backed Rudel state.
