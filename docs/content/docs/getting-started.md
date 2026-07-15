---
title: "Getting Started"
description: "Install Rudel and create your first sandbox."
path: "getting-started"
order: 1
section: "Documentation"
meta_title: "Getting Started"
meta_description: "Install Rudel and create your first sandbox."
---

## Install

Install Rudel with Composer and activate it like a normal WordPress plugin.

```bash
composer require rudel/rudel
wp plugin activate rudel
```

Check the runtime status:

```bash
wp rudel status
```

## Install the bootstrap

Rudel needs an early bootstrap line in `wp-config.php` so it can select a sandbox before WordPress finishes loading.

```bash
wp rudel bootstrap install
```

The bootstrap is idempotent. Running the command again does not duplicate the injected line.

## Create a sandbox

```bash
wp rudel create --name=alpha --theme=twentytwentyfour
```

Rudel creates a generated sandbox ID, clones the host WordPress tables into a sandbox-specific table prefix, copies the active theme into the sandbox directory, and stores the runtime record in the host WordPress database.

## Use WP-CLI inside a sandbox

```bash
RUDEL_ENVIRONMENT=alpha-1234 wp option get siteurl
```

## Route HTTP into a sandbox

```bash
curl -H 'X-Rudel-Environment: alpha-1234' http://localhost:8000/
```

You can also use the `rudel_environment` cookie for browser sessions.

## Next steps

- [Creating Environments](/docs/environments/creating)
- [Managing Environments](/docs/environments/managing)
- [Isolation](/docs/environments/isolation)
- [Runtime State](/docs/features/runtime-state)
