# Favorite CMS — Theme and Plugin Contracts

## Extension identity
Every extension needs a unique stable identifier.

A manifest should declare at minimum:
- id
- type
- name
- version
- description
- author
- license
- minimum supported Core version

Support additional metadata such as dependencies, optional dependencies, permissions, tags, repository/homepage, screenshots and changelog where useful.

## Lifecycle
Use this lifecycle:

discover
→ validate
→ compatibility/dependency check
→ install
→ register
→ activate
→ run
→ update
→ deactivate
→ uninstall

Each transition must have clear success/failure behavior.

## Package validation
Treat ZIP packages as untrusted.

Validate:
- archive structure
- manifest
- extension ID/type
- version
- required Core version
- dependencies
- conflicts
- file paths
- unsafe paths
- duplicate/conflicting installation targets

Never extract paths that can escape the intended extension directory.

## Plugin contract
A plugin can register:
- routes
- services
- events/hooks
- permissions
- settings
- admin pages
- content types
- API routes
- frontend assets
- templates/components
- migrations

Do not allow plugins to silently modify Core source.

## Theme contract
A theme can register:
- layouts
- page templates
- components
- partials
- widgets/areas
- assets
- theme configuration
- presentation presets

Themes consume content and plugin-provided public capabilities.

## Dependency model
Prefer capability-based requirements when possible.

Example concept:
- media.player
- booking.reservation
- shop.catalog

Avoid unnecessary hard-coded dependencies on one implementation.

When activation is blocked, the admin should explain:
- what is missing
- what version/capability is required
- what conflicts
- what action can resolve it

## Template resolution
Use deterministic precedence:

Theme override
→ Plugin default
→ System default
→ safe not-found/fallback

Theme overrides must not require editing plugin source code.

## Safe activation
Theme/plugin activation should be a guarded operation.

Validate before activation.

If activation fails:
- retain previous working state
- restore relevant registrations/configuration
- log an actionable diagnostic
- do not expose sensitive internals to the visitor

## Updates
Updates should preserve:
- user data
- settings where compatible
- extension state

Use migrations for schema changes.

Do not silently overwrite user-generated storage.

## Uninstall
A plugin/theme uninstall must clearly distinguish:
- code removal
- configuration removal
- optional data cleanup

Never delete user content merely because a presentation theme was removed.
