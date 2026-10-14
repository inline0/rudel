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

The sandbox serves a successful checkout immediately:

- A theme checkout becomes the active sandbox theme, including themes not installed on the host. A same-name host theme is replaced only inside the sandbox. Child themes use an installed parent copied into the sandbox.
- A plugin checkout with a valid plugin header is activated in the sandbox and loaded from its local checkout. The generated mu-plugin loader runs it in WordPress's global scope and prevents a same-name host plugin from loading twice. Asset URLs generated with `plugins_url()` or `plugin_dir_url()` point to the checkout. Host requests keep using the host plugin files and activation state.

Rudel records the resolved checkout and active theme or plugin in the environment metadata. The checkout remains available to `wp rudel push`.

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
