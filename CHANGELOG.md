# Changelog

## v0.3.6 - 2026-04-05

- Fixed prefixed preview `wp-admin/` and `wp-login.php` so direct PHP entrypoints now execute through the same previewed environment without leaking host state or crashing in app previews.
- Kept prefixed static core assets working under the same preview path, so login/admin HTML and its dependent `wp-includes/` and `wp-admin/` assets resolve together end to end.
- Added live `wp-env` coverage for app-preview admin/login flows, alongside the existing sandbox preview coverage.

## v0.3.5 - 2026-04-05

- Fixed prefixed app preview routing so `__rudel/{app}/`, `wp-admin/`, `wp-login.php`, and prefixed static assets behave like one subpath site.
- Scoped the `rudel_sandbox` cookie to the active preview path instead of `/`, so host `/` and host `/wp-admin/` no longer inherit the last previewed app.
- Moved preview routing into the runtime MU plugin path so preview environments work even when the Rudel plugin is not active inside the previewed app itself.
- Added integration, unit, and live `wp-env` coverage for the prefixed preview runtime.

## v0.3.4 - 2026-04-05

- Applied site option overrides during app provisioning so cloned and provisioned apps can be branded/configured at creation time.

## v0.3.3 - 2026-04-05

- Hardened content cloning for live development trees with noisy symlinks and mutable workspace files.

## v0.3.2 - 2026-04-05

- Added prefixed preview mode for Rudel apps so permanent apps can also be opened through `__rudel/{app}/` when same-origin operator tooling needs an embedded preview surface.
