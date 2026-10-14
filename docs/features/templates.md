---
title: "Templates"
description: "Create reusable sandbox templates from saved tables and owned files."
path: "features/templates"
order: 120
section: "Features"
meta_title: "Templates"
meta_description: "Save sandbox tables and owned theme or plugin files, then create independent sandboxes from that point-in-time template."
---

# Templates

A template captures a sandbox's database tables and environment-owned theme and plugin files at the time it is saved.

```bash
wp rudel template save alpha-3be7 --name=checkout-baseline --description="Checkout baseline"
wp rudel create --name=feature-b --template=checkout-baseline
wp rudel template list
wp rudel template delete checkout-baseline --force
```

`create --template` validates the saved template, copies its tables into a fresh sandbox prefix, rewrites WordPress role and capability keys for that prefix, and copies its saved files. Later source changes or deletion do not change the template. A theme override can select another installed host theme. Choose either `--template` or `--clone-from`.

## Stored state

Saved tables live in the host MySQL database under a dedicated `rudel_tpl_{hash}_` prefix. Metadata lives in the shared Rudel artifacts table. Owned files are saved in `wp-content/rudel-templates/{name}/wp-content`, protected from Apache HTTP requests by a deny rule.

Shared host plugin files, uploads, WordPress core, logs and existing snapshots are excluded. Local Git-created plugin overrides are retained; Git repository metadata and original branch tracking are excluded.

Deleting a template removes its saved tables, files and metadata. Existing sandboxes created from it keep their own copies. `template delete` asks for confirmation; `--force` skips the prompt.

## Existing file-only templates

Older templates had no database capture. Rudel migrates their metadata into MySQL, but creating a sandbox from one requires saving a new template from an existing sandbox. This avoids substituting the source site's current database for a missing saved database.

The built-in `blank` and `overlay` labels create a sandbox from the host and are reserved template names. Use snapshots to restore one existing sandbox.
