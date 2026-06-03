# Rudel Request Overlay Runtime PRD

Status: Implemented in this branch

## Summary

Rudel should move from a multisite-only environment orchestrator to a request-selected overlay runtime for WordPress.

The target model is intentionally smaller and cleaner:

- one normal WordPress installation
- isolated environment database tables through generated table prefixes
- isolated active theme copies
- shared WordPress core, shared plugins, and shared uploads by default
- environment selection through request metadata such as headers or cookies

This removes the requirement that every Rudel environment is a multisite subsite. Instead, Rudel becomes a request-scoped runtime switcher that can make one WordPress installation behave like different environments depending on the selected Rudel environment.

## MVP Decision

The first overlay runtime supports exactly two isolation layers:

- database tables through an environment-specific WordPress table prefix
- the selected active theme through an environment-owned theme copy

Everything else remains shared in this pass:

- WordPress core
- plugin files
- upload files
- PHP process resources
- WordPress users, unless WordPress itself stores a user-related value in prefixed site tables

This keeps the product model clean without pretending Rudel provides container-level isolation.

## Goals

- Allow environments without requiring WordPress multisite.
- Route a request into one environment by selecting a Rudel environment ID.
- Switch the active database table prefix early enough for WordPress to load environment-specific content and options.
- Load an environment-specific active theme copy without replacing the global site theme.
- Keep plugins shared for now to avoid WordPress plugin-loader complexity.
- Keep uploads shared for now unless a later product requirement needs upload isolation.
- Preserve Rudel's registry tables as the durable source of truth for environment metadata.
- Keep the runtime understandable for embedding products that need lightweight, request-selected sandboxes.

## Non-Goals

- Do not isolate plugins in this first overlay runtime.
- Do not isolate uploads in this first overlay runtime.
- Do not require symlinks.
- Do not introduce a second runtime source of truth.
- Do not keep multisite as a required substrate.
- Do not emulate full OS/container isolation.
- Do not solve full user isolation in this pass.
- Do not support path-routed environments as the primary design unless headers/cookies prove insufficient.

## Runtime Model

An environment is selected per request.

Selection inputs, in priority order:

1. explicit trusted header, for example `X-Rudel-Environment`
2. explicit Rudel cookie
3. optional CLI/runtime override for local tooling
4. no environment selected, meaning normal host WordPress

When an environment is selected, Rudel applies:

- an environment table prefix, for example `wp_a4nmv7_`
- an environment theme root, for example `wp-content/rudel-environments/a4nmv7/themes`
- active theme overrides for `template`, `stylesheet`, and related theme options

When no environment is selected, WordPress behaves normally.

## Database Overlay

Each environment gets a generated database table prefix.

Example:

```text
Host prefix:        wp_
Environment slug:  a4nmv7
Runtime prefix:    wp_a4nmv7_
```

That prefix owns the environment's WordPress content tables:

```text
wp_a4nmv7_posts
wp_a4nmv7_postmeta
wp_a4nmv7_options
wp_a4nmv7_terms
wp_a4nmv7_termmeta
wp_a4nmv7_term_taxonomy
wp_a4nmv7_term_relationships
wp_a4nmv7_comments
wp_a4nmv7_commentmeta
```

Rudel registry tables remain separate and durable:

```text
wp_rudel_environments
wp_rudel_worktrees
...
```

Those registry tables store the environment ID, slug, table prefix, theme slug, paths, lifecycle state, timestamps, and policy metadata.

### Table Prefix Requirements

- The WordPress base DB prefix must remain intact.
- Rudel appends the environment identifier after the WordPress base prefix.
- `wp_` can become `wp_a4nmv7_`.
- Rudel must never replace the WordPress base prefix with an unrelated prefix.
- Environment prefixes must be deterministic, filesystem-safe, database-safe, and collision-resistant.
- Runtime bootstrap must set the effective table prefix before WordPress creates `$wpdb` table names.

## Theme Overlay

Each environment may have one or more copied themes in:

```text
wp-content/rudel-environments/{environment_id}/themes/{theme_slug}
```

For a selected environment, Rudel should:

- register the environment theme directory as a valid theme root
- force the active `template` option to the environment theme slug
- force the active `stylesheet` option to the environment theme slug
- ensure `get_stylesheet_directory()`, `get_template_directory()`, theme assets, and theme metadata resolve to the environment copy

This gives each environment an isolated active theme while keeping plugins and uploads shared.

### Theme Constraints

- Child/parent theme support must be explicit.
- If the environment theme is a child theme, Rudel must know whether the parent is also copied or shared.
- Theme slugs must not collide ambiguously across host and environment theme roots.
- Theme updates inside one environment must only mutate the environment theme copy.
- Theme deletion must not delete the host theme.

## Shared Plugins

Plugins stay shared in this model.

Rationale:

- WordPress plugin loading is built around one `WP_PLUGIN_DIR`.
- Per-plugin runtime override would require either an environment-local `wp-content` overlay or custom loader behavior.
- That complexity is not needed for the initial product goal.

Implications:

- Active plugin list comes from the selected environment's DB tables if `wp_options` is isolated.
- Plugin files are still loaded from the host plugin directory.
- If an environment activates a plugin, it activates the shared plugin code for that environment's DB prefix.
- Plugin code changes are global because plugin files are shared.

## Shared Uploads

Uploads stay shared in this model.

Rationale:

- Theme experiments usually do not require upload isolation.
- Upload isolation can be added later with `upload_dir` filters if required.
- Keeping uploads shared makes the initial runtime cheaper and easier to operate.

Implications:

- Media metadata can be environment-specific because the DB prefix is isolated.
- Physical files are shared.
- Deleting media inside an environment must be treated carefully because deleting the physical file could affect host or sibling environments.

For this reason, destructive media-file operations may need to be guarded or made opt-in.

## Request Bootstrap

Rudel needs an early bootstrap layer that runs before WordPress resolves table names and theme state.

Responsibilities:

- inspect trusted request metadata
- resolve the Rudel environment record from registry tables
- validate the environment is active and allowed for the request
- set the effective WordPress table prefix
- register runtime constants for observability, for example `RUDEL_ENVIRONMENT_ID`
- register theme-root and active-theme overrides early enough for theme loading

The bootstrap must be safe when no environment is selected.

## Public API

The API should expose overlay-specific operations directly.

Candidate environment operations:

```php
Rudel::create_environment([
    'name' => 'Review Theme',
    'theme' => 'divine-child',
    'source' => 'host',
]);

Rudel::activate_environment($environment_id);
Rudel::destroy_environment($environment_id);
Rudel::clone_database($environment_id, 'host');
Rudel::clone_theme($environment_id, 'divine-child');
Rudel::environment_url($environment_id);
```

Candidate request helpers:

```php
Rudel::current_environment();
Rudel::with_environment($environment_id, callable $callback);
Rudel::environment_cookie($environment_id);
```

The exact naming can change during implementation, but the API should describe overlays, not multisite sites.

## CLI

The CLI should remain simple:

```bash
wp rudel create --name=review-a --theme=divine-child
wp rudel list
wp rudel info review-a
wp rudel destroy review-a
wp rudel clone-db review-a --from=host
wp rudel clone-theme review-a --theme=divine-child
```

Optional local request helpers:

```bash
wp rudel cookie review-a
wp rudel header review-a
```

Existing multisite-specific language should be removed or renamed when this model lands.

## Storage

Environment directory:

```text
wp-content/rudel-environments/{environment_id}/
├── themes/
│   └── {theme_slug}/
├── logs/
├── snapshots/
└── metadata/
```

Runtime source of truth:

- Rudel registry tables in the host WordPress database
- no JSON runtime config
- no per-environment SQLite

Filesystem directories are runtime assets, not authoritative metadata.

## Security

Environment selection must not trust arbitrary public input by default.

Required controls:

- headers are only trusted when explicitly enabled or when requests come through a trusted harness/proxy
- cookies must be signed or scoped to authenticated/local usage
- environment IDs must be validated strictly
- inactive/deleted environments must not resolve
- request selection must not expose private environments to anonymous users unless explicitly allowed
- destructive file operations must be scoped to owned environment paths

## Compatibility Direction

The current multisite runtime and the new overlay runtime are conceptually different. The overlay runtime should be treated as the new product architecture, not as a compatibility shim around multisite.

Implementation guidance:

- make overlay the default runtime model
- remove multisite-specific assumptions from public docs and normal CLI output
- avoid a complex compatibility layer unless a concrete downstream product still needs it
- keep any temporary legacy code internal and clearly separated from the overlay path

## Testing

Unit coverage:

- environment ID and table-prefix generation
- request environment resolution from header/cookie
- invalid environment rejection
- theme-root registration behavior
- active theme option overrides
- registry repository create/update/delete flows

Integration coverage:

- create environment from host DB
- selected request sees environment DB options
- unselected request sees host DB options
- selected request loads environment theme copy
- host request still loads host theme
- sibling environments do not share DB state
- deleting an environment drops only its environment tables and owned theme copies

E2E coverage:

- browser request without cookie/header shows host site
- browser request with environment cookie shows environment DB state
- browser request with environment header shows environment DB state
- environment request loads CSS/assets from copied theme
- host remains unchanged after environment theme edits
- plugin files remain shared and available
- uploads behavior is documented and guarded

## Implementation Checklist

- Add or update environment metadata for table prefix and active theme slug.
- Create environment DB tables by cloning the host WordPress tables into the generated prefix.
- Ensure Rudel registry tables are never cloned into environment prefixes.
- Set the selected request's table prefix before WordPress resolves `$wpdb` table names.
- Copy the selected active theme into the environment theme root.
- Register the environment theme root and force template/stylesheet values for selected requests.
- Keep plugin and upload paths pointed at the host WordPress installation.
- Scope destroy/cleanup to the generated environment table prefix and owned theme directory.
- Update CLI language from multisite/subsite to environment/overlay.
- Update docs so the runtime model is DB-backed, single-site capable, and DB/theme isolated only.

## Open Questions

- Should the selected environment be available only to authenticated users by default?
- Should query-parameter environment selection exist only in local/dev mode?
- Should uploads be read-only from environments until upload isolation is implemented?
- Should theme copies be created eagerly at environment creation or lazily on first request?
- Should parent themes be copied automatically when cloning a child theme?

## Acceptance Criteria

- Rudel can run on a normal single-site WordPress installation.
- A request with no environment selection behaves exactly like the host site.
- A request with a valid environment selection uses that environment's DB table prefix.
- A selected environment can use a copied active theme without changing the host active theme.
- Plugins stay shared and documented as shared.
- Uploads stay shared and documented as shared.
- Rudel runtime metadata stays DB-backed only.
- Multisite is no longer required for the core overlay runtime.
