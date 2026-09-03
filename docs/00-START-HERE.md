# Favorite CMS — Codex Standalone Build Rules

## IMPORTANT
These files are the complete project-direction brief for the new Favorite CMS build.

Do NOT require access to an older repository or older documentation to understand the target architecture. Treat these rules as the authoritative project brief for this new build.

Do not spend implementation time recreating an old architecture that conflicts with these requirements.

## Goal
Build a complete, fast, secure, modular CMS that ordinary users can install on common shared hosting.

The product should feel as easy to deploy as a typical ZIP-installable CMS:
- upload ZIP
- extract
- connect domain
- visit domain
- complete browser installer
- create administrator
- start building the website

The CMS must be a complete product, not merely a framework skeleton.

## Core product principle
Core = infrastructure and essential general-purpose CMS capabilities.
Plugins = optional features/business capabilities.
Themes = presentation/design.
Content = user data.
Settings = user choices.

## Default installation must already be a usable CMS
A fresh installation must include the essential functionality needed to create a normal website without installing optional plugins.

At minimum, the default installation must provide:
- Dashboard/admin foundation
- Posts
- Pages
- Categories/tags or equivalent basic taxonomy
- Media library and upload management
- Users
- Roles and permissions
- Menus/navigation
- General site settings
- Basic writing/reading/content settings
- Permalink/URL settings
- Media settings
- Theme management
- Theme upload/install/activate
- Basic theme customization/settings
- Plugin management
- Plugin upload/install/activate/deactivate/update
- Basic built-in SEO
- SEO/meta defaults
- Sitemap support
- Robots configuration
- Social metadata support
- Standard 404/error handling and basic site templates

These are foundational CMS capabilities, not optional plugins.

After installation, a user must be able to:
create a post/page → upload media → configure menu/settings/SEO → choose or upload a theme → publish a working website.

The Core must remain lightweight. Specialized business systems must remain plugins.

## Primary deployment target
Ordinary PHP shared hosting with a relational database.

The normal installation must not require:
- a separate application server
- a continuously running worker
- a frontend server
- a JavaScript runtime in production
- containers
- special VPS infrastructure

Development tooling may exist, but production must be deployable as a self-contained release package.

## Performance
Fast and lightweight are first-class requirements.

Avoid:
- unnecessary dependencies
- unnecessary network requests
- large client-side frameworks when simple server rendering is enough
- loading every plugin/service on every request
- expensive database queries without need
- architecture that exists only for theoretical extensibility

Use:
- server-side rendering
- lightweight browser JavaScript
- efficient SQL
- proper indexes
- lazy loading/scoped services where useful
- safe caching
- optimized assets
- predictable request paths

Do not sacrifice security or correctness merely for micro-optimizations.

## Product flexibility
The same CMS must be capable of becoming:
- official/company website
- blog/news site
- portfolio
- e-commerce site
- digital-product site
- physical-product shop
- subscription/membership site
- hotel/room booking site
- event/ticket booking site
- multimedia/video/music/movie/series site
- other specialized websites through plugins and themes

The CMS itself should not force a website category.

## Theme philosophy
A theme controls presentation.

Themes may contain:
- layouts
- pages
- templates
- components
- partials
- widgets/areas
- styles
- scripts
- images/fonts/assets
- theme settings
- presentation presets

Themes must not own core business data or business logic.

Theme switching must not delete or alter user content.

## Plugin philosophy
Plugins add capabilities/features.

Plugins may provide:
- admin screens
- public routes
- content types
- database tables/migrations
- services
- API endpoints
- settings
- permissions/capabilities
- hooks/events
- frontend components/templates
- assets

Plugins should communicate through public contracts, services, events and hooks instead of tightly coupling to private implementation details.

## Failure philosophy
An optional feature must fail safely.

A bad plugin/theme/update/cache/provider should not unnecessarily destroy unrelated CMS functionality.

Theme activation:
- validate first
- keep a known-good fallback
- activate safely
- restore previous theme if activation fails

Plugin installation/update:
- validate first
- check compatibility/dependencies
- make changes atomically or rollback safely
- never intentionally leave the installation half-updated

## Security baseline
Treat all user-controlled data and uploaded packages as untrusted.

Required protections include:
- prepared/parameterized database queries
- output escaping
- CSRF protection
- secure password hashing
- secure sessions/cookies
- authorization checks
- upload validation
- ZIP path traversal protection
- safe extraction
- permission checks
- secure secret/config handling
- no sensitive information in production error responses
- protection against unauthorized file access
- safe redirect handling
- rate limiting where appropriate

Do not invent cryptography. Use proven platform/library mechanisms.

## API
The CMS should expose a clean API layer for legitimate integrations and future extensions.

API contracts should be versionable and permission-aware.

Do not make API functionality dependent on a specific frontend.

## Backup and migration
A site should be portable.

A practical full backup/recovery set consists of:
- application files
- installed themes/plugins
- user-generated storage/media
- database
- required site configuration/state

A compatible installation should be movable primarily by:
1. copying/restoring files
2. importing/restoring the database
3. updating environment/database configuration
4. visiting the site and completing any required migration step

Do not bind user data to one hosting vendor or machine-specific path.

## Development behavior
Do not build a fake/demo CMS.

Every implemented subsystem should have real integration behavior.

Prefer small, testable modules over giant classes.

Do not modify Core simply because a plugin/theme can solve the requirement.

## Definition of done
A feature is not complete because files/classes exist.

It is complete when:
- behavior works end-to-end
- security boundaries exist
- errors are handled
- data persists correctly
- contracts are testable
- relevant tests pass
- the feature works with the rest of the application
- migration/update implications are handled
- documentation is added where the user/developer needs it
