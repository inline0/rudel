---
title: "Templates"
description: "Save a copy of a sandbox's files for later reference."
path: "features/templates"
order: 120
section: "Features"
meta_title: "Templates"
meta_description: "Rudel templates save a file copy of a sandbox directory. In the current release no command creates a sandbox from a saved template."
---

# Templates

A template is a named file copy of one sandbox directory.

```bash
wp rudel template save alpha-3be7 --name=checkout-baseline --description="Checkout theme baseline"
wp rudel template list
wp rudel template delete checkout-baseline
```

`template delete` asks for confirmation; `--force` skips the prompt.

## What a template holds

`template save` copies the sandbox directory (theme copy, snapshot metadata, logs and the other files Rudel manages there) to:

```text
wp-content/rudel-templates/{name}/wp-content
wp-content/rudel-templates/{name}/template.json
```

`template.json` records the name, description, creation time, source sandbox ID and URL. No database tables are saved.

## Current limits

In the current release nothing reads a saved template back. `wp rudel create --template=<name>` and the `template` option of `Rudel::create()` only store the given string as a label on the new record; they do not check that the template exists or copy anything from it ([issue #7](https://github.com/inline0/rudel/issues/7)).

To start several sandboxes from the same state today, prepare one sandbox and clone it:

```bash
wp rudel create --name=feature-b --clone-from=alpha-3be7
```

Use snapshots when you need a recovery point for one existing sandbox.
