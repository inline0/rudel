---
title: "Git"
description: "PHP-native Git workflows for Rudel theme worktrees."
path: "features/git"
order: 130
section: "Features"
meta_title: "Git"
meta_description: "PHP-native Git workflows for Rudel theme worktrees."
---

# Git

Rudel can use PHP-native Git operations for tracked theme worktrees. It does not require the host `git` binary.

Create a sandbox with a tracked theme source:

```bash
wp rudel create \
  --name=feature-x \
  --theme=my-theme \
  --git=https://example.test/my-theme.git \
  --branch=main \
  --dir=themes/my-theme
```

Rudel stores worktree metadata in `wp_rudel_worktrees`.

## Push changes

```bash
wp rudel push feature-x-1234 --message="Update checkout template"
```

The push command uses the sandbox's tracked worktree metadata unless you override it with command options.

## Worktree identity

Rudel gives each linked worktree an explicit metadata name. The identity is based on the environment and tracked repository context, not just the checkout directory basename.

That prevents collisions when multiple sandboxes use the same theme slug.
