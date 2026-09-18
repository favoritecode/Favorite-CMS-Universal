# Favorite CMS — Architecture Rules

## Application model
Use one installable web application.

## Default Core baseline
The fresh installation must immediately function as a general-purpose CMS.

Built-in baseline capabilities:
- posts
- pages
- basic taxonomy
- media library
- users
- roles/permissions
- menus/navigation
- general/content settings
- permalink/URL configuration
- theme management and theme upload
- plugin management and plugin upload
- basic SEO/metadata
- sitemap/robots/social metadata
- standard 404/error handling

Do not make users install plugins just to obtain these basic CMS functions.

Keep specialized business functionality out of Core.

## Conceptual layers
- HTTP/public entry
- Bootstrap/Core
- Services/Engines
- Routing
- Authentication/Authorization
- Plugin system
- Theme/Rendering system
- Content/data
- Database
- Storage
- Admin
- API

Do not split the normal frontend and backend into separately deployed applications.

## Front controller
Use a stable public entry point and route requests through the application kernel.

Keep sensitive application code outside directly public paths where the hosting layout permits.

## Service boundaries
Use explicit service interfaces/contracts for major responsibilities.

Examples:
- database
- configuration
- routing
- authentication
- authorization
- rendering
- themes
- plugins
- storage
- media
- cache
- events/hooks
- logging
- API
- updates
- migrations

Do not turn every tiny helper into an engine.

## Dependency direction
Preferred direction:

Presentation/theme
→ public engine/plugin contracts
→ Core services

Business plugins
→ public Core/engine contracts

Core
→ must not depend on optional business plugins.

Themes
→ must not depend on private plugin internals.

## Request lifecycle
HTTP request
→ bootstrap
→ configuration
→ Core services
→ route resolution
→ middleware/security
→ authentication when needed
→ authorization when needed
→ controller/engine/plugin
→ rendering/response
→ response

Keep this lifecycle deterministic.

## Database architecture
Use a portable relational database abstraction appropriate for common shared hosting.

Requirements:
- migrations
- schema versioning
- transactions
- prepared statements
- indexes
- predictable connection handling
- safe upgrade path

Do not scatter raw database logic through themes.

## Storage architecture
Separate application code from user-generated storage.

Provide an abstraction so media/files are not tied to one hard-coded machine path.

Protect private/sensitive files from direct public access.

## Configuration
Separate:
- installation/environment configuration
- site settings
- plugin settings
- theme settings
- secrets

Never expose secrets to frontend code.

## Caching
Cache should accelerate the application, not become a single point of failure.

Cache invalidation must be considered for:
- settings
- theme activation
- plugin activation
- routes
- templates
- permissions
- content changes

If a nonessential cache backend fails, the CMS should degrade safely where possible.

## Background work
Do not require a permanently running process for the basic CMS.

If delayed jobs/scheduling are needed, design a hosting-compatible mechanism such as cron-compatible execution or safe request-triggered processing where appropriate.

The website must remain usable when background work is delayed.
