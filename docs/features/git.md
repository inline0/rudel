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

Rudel uses PHP-native Git operations through Pitmaster. It does not require the host `git` binary. Remotes are reached over HTTP(S) or SSH.

Each sandbox has one branch name, `rudel/{sandbox-id}`, used by every Git workflow below.

## Clone a remote into a new sandbox

```bash
wp rudel create --name=feature-x --git=https://example.test/my-theme.git
```

Rudel creates the sandbox, clones the remote into `themes/my-theme` inside the sandbox directory (the directory name is the repository name), creates the branch `rudel/{id}` from the default branch and checks it out. With `--type=plugin`, the checkout goes to `plugins/my-theme` instead. The remote, directory and base branch are stored on the sandbox for later pushes. If the clone fails, the sandbox is kept and the command prints a warning.

The checkout is a working copy for `wp rudel push`. In the current release the sandbox does not serve it ([issue #11](https://github.com/inline0/rudel/issues/11)):

- The sandbox serves the theme chosen with `--theme`, or the host's active theme, copied from the host when the sandbox is created. `--theme` must name a theme installed on the host, so a checkout is served only when the repository name matches that slug and the clone then writes into the copied theme directory.
- Plugins always load from the host's plugins directory, so a `--type=plugin` checkout never runs.

## Linked worktrees

When you clone a sandbox whose theme directory is a Git repository, Rudel creates a linked worktree for the new sandbox instead of copying files:

```bash
wp rudel create --name=feature-y --clone-from=feature-x-3be7
```

The worktree is on the branch `rudel/{new-id}`. Rudel records it in the new sandbox's metadata under an explicit worktree name based on the sandbox ID, content type and theme slug, so two sandboxes that use the same theme slug do not collide. Destroying the sandbox removes the worktree and deletes its branch.

`wp rudel cleanup --merged` considers only these linked worktrees. See [Cleanup](/docs/features/cleanup).

## Push changes

```bash
wp rudel push feature-x-3be7 --message="Update checkout template"
```

`push` commits every change in the checkout and pushes the branch `rudel/{id}` to `origin`. It returns `No changes to push.` when nothing changed.

- `--git=<remote>` sets the remote when none is stored; after the first successful push it is remembered.
- `--dir=<dir>` picks the directory relative to the sandbox directory, for example `themes/my-theme`. It defaults to the directory stored by `--git`, otherwise the whole sandbox directory.
- `--message` defaults to `Update from Rudel sandbox`.

From PHP, `Rudel::push( $id, $remote_url, $message, $subdir )` does the same and fires the `rudel_*_environment_push` hooks.

## Commit identity

If the checkout has no `user.name` or `user.email` in its Git config, Rudel writes `Rudel` and `rudel@localhost`. Define `RUDEL_GIT_AUTHOR_NAME` and `RUDEL_GIT_AUTHOR_EMAIL` in `wp-config.php` to use other values.
