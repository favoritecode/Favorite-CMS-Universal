# Favorite CMS — Full Architecture Specification

## 1. Product architecture

Favorite CMS is a complete, standalone, shared-hosting CMS.

The application has clearly separated logical Backend and Frontend layers, but they are delivered and installed as ONE CMS package. They are not separate applications or separate production servers.

Conceptually:

Favorite CMS
├── Core / Backend
├── Admin Panel
├── Engines / Services
├── Database
├── Plugin System
├── Theme System
├── Rendering System
├── Frontend
├── Installer
├── Storage
└── API

The frontend is rendered by the CMS and theme system. It is embedded in the same installable application.

## 2. Main architecture layers

### Core / Backend
The Core is responsible for platform infrastructure:
- bootstrap
- application lifecycle
- configuration
- service container
- routing
- HTTP/request handling
- authentication
- authorization
- users
- roles
- permissions/capabilities
- database abstraction
- migrations
- storage abstraction
- logging
- error handling
- security
- events/hooks
- cache
- extension registration
- plugin manager
- theme manager
- API infrastructure
- update/recovery infrastructure

Core must remain generic.

### Frontend
Frontend is the public website presentation layer.

It contains:
- theme rendering
- layouts
- templates
- components
- partials
- widgets/areas
- navigation rendering
- content presentation
- frontend assets
- responsive UI
- SEO output

Frontend must not become a separate Node/SPA application requirement.

### Admin Panel
Admin is part of the same CMS application.

Required areas:
- Dashboard
- Posts
- Pages
- Media
- Comments
- Users
- Roles/Permissions
- Menus
- Themes
- Plugins
- Settings
- SEO
- system/update/diagnostic areas

Plugin-specific admin pages are added by plugins.

## 3. Fresh installation baseline

Immediately after installation, the CMS must already be a usable general-purpose CMS.

Built-in functionality:

Dashboard
Posts
Pages
Categories/Tags
Media Library
Comments/moderation where enabled
Users
Roles
Permissions
Menus
Settings
Themes
Theme Upload
Theme Activation
Plugin Management
Plugin Upload
Plugin Activation/Deactivation
Basic SEO
Sitemap
Robots configuration
Social metadata
Search
Permalinks
404 handling

These are NOT optional plugins.

A user must be able to install the CMS and immediately create a normal website/blog without installing any additional plugin.

## 4. Content architecture

Core content model must support:
- Posts
- Pages
- basic taxonomy
- authors/users
- statuses
- drafts
- publishing
- scheduling where supported
- revisions where supported
- featured media
- slugs/permalinks
- metadata
- search

Content data must be independent from themes.

A theme only renders content.

Plugins may register specialized content/entities.

## 5. Users, roles and permissions

Core provides:
- user accounts
- authentication
- sessions
- roles
- capabilities/permissions
- profile management
- administrator access control

Plugins may register additional capabilities.

Every protected operation is authorized server-side.

## 6. Settings architecture

Provide separate configuration/settings scopes:

System/environment configuration
Site settings
Content settings
User settings
Theme settings
Plugin settings
SEO settings

Theme/plugin settings must not be mixed into Core infrastructure configuration.

## 7. Theme architecture

Themes are uploadable packages.

Conceptually:

themes/
└── theme-id/
    ├── theme.json
    ├── layouts/
    ├── templates/
    ├── pages/
    ├── components/
    ├── partials/
    ├── widgets/
    ├── assets/
    ├── config/
    └── languages/

Theme responsibilities:
- design
- layout
- templates
- components
- styling
- frontend JavaScript
- presentation settings

Theme must not own business data.

Required default theme templates:
- homepage
- posts archive
- single post
- page
- category
- tag
- search
- 404
- header
- navigation
- footer

Theme upload/activation must be validated and rollback-safe.

## 8. Plugin architecture

Plugins are uploadable packages.

Conceptually:

plugins/
└── plugin-id/
    ├── plugin.json
    ├── src/
    ├── admin/
    ├── routes/
    ├── services/
    ├── database/
    ├── templates/
    ├── assets/
    ├── config/
    ├── languages/
    └── tests/

A plugin may provide:
- admin pages
- frontend routes
- services
- content/entity types
- database tables/migrations
- settings
- permissions
- APIs
- events/hooks
- templates/components
- assets

Plugin lifecycle:

discover
→ validate
→ dependency check
→ compatibility check
→ install
→ register
→ activate
→ run
→ update
→ deactivate
→ uninstall

## 9. Plugin/theme relationships

Plugins provide functionality.

Themes provide presentation.

Plugins may provide default templates/components for their functionality.

Themes may override those templates/components.

Resolution order:

Theme Override
→ Plugin Default
→ Core/System Default
→ Safe 404/Fallback

Do not require editing plugin source code to customize its presentation.

Themes should depend on capabilities/contracts rather than private plugin implementation details.

## 10. Rendering architecture

Rendering Engine is a distinct system between application logic and frontend output.

Conceptually:

Request
→ Router
→ Controller/Engine/Plugin
→ View/Template resolution
→ Theme override resolution
→ Layout
→ Components/partials/widgets
→ Asset resolution
→ HTML response

Rendering must not contain business logic.

Templates must use safe escaping.

## 11. Engines / platform services

The CMS should have clear boundaries for major platform responsibilities.

Examples:
- Content Engine
- Rendering Engine
- Theme Engine
- Plugin Engine
- User/Auth Engine
- Permission Engine
- Media Engine
- Search Engine
- Settings Engine
- Menu Engine
- SEO Engine
- API Engine
- Update Engine
- Cache Engine
- Notification services
- Storage services
- Database/migration services

Do not create engines merely for naming purposes. Keep implementation proportional to actual requirements.

## 12. Database

Use a shared-hosting-friendly relational database.

Required:
- schema migrations
- schema version
- transactions
- prepared statements
- indexes
- safe connection handling
- upgrade compatibility

Core tables should contain generic platform data.

Plugin-owned tables contain plugin-specific business data.

Theme installation must never store business data as theme-owned data.

## 13. Storage

Separate:
- application code
- public assets
- user uploads/media
- generated/cache data
- configuration/secrets

Media must be managed through the Media/Storage abstraction.

Do not hard-code machine-specific storage paths into user data.

## 14. API

The CMS provides an API layer independent from the frontend.

API supports:
- external integrations
- plugin endpoints
- future clients
- controlled administrative operations

API access must be authenticated/authorized where required.

API contracts should be versionable.

## 15. Installation architecture

Package:

Favorite CMS ZIP
→ upload to hosting
→ extract
→ visit domain
→ installer
→ requirements check
→ database connection
→ schema migrations
→ admin account
→ initial settings
→ installation verification
→ installer lock

The release package must contain everything required for normal production operation.

## 16. Backup and migration

The architecture must support portable deployment.

Full recovery requires:
- CMS files
- themes
- plugins
- user uploads/storage
- database
- required configuration/state

Migration to compatible hosting should primarily require:
1. restore files
2. restore database
3. update hosting/database configuration
4. visit site
5. run any required migration/repair step

Rebuild caches/generated data when necessary rather than treating cache as permanent user data.

## 17. Performance architecture

The CMS must be fast by design.

Use:
- server-side rendering
- lightweight frontend assets
- efficient database queries
- proper indexes
- scoped/lazy loading where useful
- caching where beneficial
- optimized asset delivery
- minimal Core bootstrap cost

Do not load all plugin functionality or assets on every request when unnecessary.

The default installation must remain lightweight.

## 18. Security architecture

Required:
- secure password hashing
- secure sessions
- CSRF protection
- authorization
- input validation
- output escaping
- prepared SQL
- secure uploads
- safe ZIP extraction
- path traversal protection
- safe redirects
- protected configuration
- safe production errors
- rate limiting where appropriate

Uploaded themes/plugins are untrusted packages and must be validated before installation.

## 19. Failure isolation

The platform must protect the working site from optional extension failures.

Plugin failure:
- isolate where possible
- preserve unrelated features
- provide diagnostics

Theme failure:
- retain/restore previous working theme
- preserve content

Update failure:
- rollback or recover safely

Cache failure:
- degrade to uncached operation where possible

## 20. Recommended high-level directory structure

favorite-cms/
├── app/
│   ├── Core/
│   ├── Engines/
│   ├── Http/
│   ├── Services/
│   ├── Auth/
│   ├── Admin/
│   └── Support/
├── frontend/
│   ├── components/
│   ├── layouts/
│   ├── templates/
│   └── assets/
├── config/
├── database/
│   ├── migrations/
│   └── seeds/
├── public/
│   ├── index.php
│   └── assets/
├── admin/
├── plugins/
├── themes/
├── storage/
├── routes/
├── installer/
├── tests/
└── vendor/

The exact directory names may be adjusted during implementation if the resulting structure is cleaner, but the logical boundaries must remain.

## 21. Critical architecture rule

Backend and frontend are logically separated but remain one deployable CMS.

Do NOT create:
- separate frontend server
- mandatory frontend build server
- separate backend application
- architecture that requires multiple processes for normal hosting

The goal is:

One ZIP
→ One hosting account
→ One database
→ One domain
→ Complete CMS.

## 22. Business plugin boundary

The base CMS must be complete for ordinary websites.

Specialized plugins may add:
- E-commerce
- Hotel Booking
- Ticket/Event Booking
- Membership/Subscription
- Multimedia/Movie/Music systems
- Payment gateways
- advanced analytics
- other specialized business features

Installing these plugins must extend the CMS without requiring Core rewrites.

## 23. Final acceptance

A clean installation is acceptable only when:

- installer works
- admin works
- dashboard works
- posts work
- pages work
- taxonomy works
- media works
- users work
- roles/permissions work
- menus work
- settings work
- themes can be uploaded/activated
- plugins can be uploaded/activated/deactivated
- basic SEO works
- default theme renders public content
- search works
- 404 works
- backup/migration design is functional
- the application remains usable without specialized plugins
- performance and security requirements are satisfied

The product must be a complete CMS, not a dashboard prototype.
