---
title: "Creating"
description: "Create Rudel sandboxes with cloned tables and copied active themes."
path: "environments/creating"
order: 30
section: "Environments"
meta_title: "Creating"
meta_description: "Create Rudel sandboxes with cloned tables and copied active themes."
---

# Creating

## Create from the host

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Rudel generates an ID from the name plus four hex characters (for example `alpha-3be7`), clones the host WordPress tables into a generated table prefix, copies the selected theme into the sandbox directory, and records the sandbox in the host WordPress database. The sandbox's `siteurl` and `home` keep the host URL.

If `--theme` is omitted, Rudel uses the host site's active theme. The theme must be installed on the host. A child theme brings its parent theme along.

Host tables are copied, including `users` and `usermeta`. Rudel excludes its runtime metadata and the prefixes of existing sandboxes, including their snapshot tables.

## Create from another sandbox

```bash
wp rudel create --name=feature-b --clone-from=alpha-3be7
```

The new sandbox receives the source sandbox's current tables and theme. The source's snapshot tables stay with the source. When the source theme directory is a Git repository, Rudel creates a linked worktree on the branch `rudel/{new-id}` instead of a plain copy. The source ID is recorded in the new record.

## Lifecycle metadata

Sandboxes can carry metadata for cleanup and ownership:

```bash
wp rudel create \
  --name=feature-a \
  --owner=dennis \
  --labels=agent,qa \
  --purpose="Test checkout changes" \
  --ttl-days=7
```

Use `--protected` when automated cleanup must not remove the sandbox. See [Policy](/docs/features/policy).

## Limits

Creation fails when the `max_sandboxes` or `max_disk_mb` settings are reached. Both default to `0`, which means no limit. See [Configuration](/docs/configuration).

## Git checkouts

```bash
wp rudel create --name=feature-a --git=https://example.test/my-theme.git
```

Rudel creates the sandbox, clones the remote into `themes/my-theme` inside the sandbox directory and switches the checkout to the branch `rudel/{id}`. Add `--type=plugin` to clone into `plugins/my-theme` instead. A failed clone leaves the sandbox in place and prints a warning. See [Git](/docs/features/git) for what the sandbox serves from that checkout.

## Directory layout

```text
wp-content/rudel-environments/{id}/
  themes/       copied theme, plus any Git checkout
  snapshots/    snapshot metadata and theme copies
  logs/ metadata/ tmp/
  wp-cli.yml
  debug.log     when debug logging is active
```
