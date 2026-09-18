# Favorite CMS — Rendering, Admin and UX Rules

## Rendering engine
Rendering is an independent responsibility.

It should resolve:
- route
- template
- layout
- component
- widget/area
- asset
- theme override
- plugin-provided defaults

The rendering engine should build the final response without embedding business logic.

## Server-rendered frontend
The default website should be server-rendered for:
- fast first response
- simple shared-hosting deployment
- minimal client requirements
- SEO friendliness

Browser JavaScript should enhance the experience, not be mandatory for basic page rendering.

## Template safety
Templates must have safe escaping helpers and clear rules for raw HTML.

Do not use unsafe dynamic evaluation merely to make themes flexible.

## Slots/areas
Support predictable theme areas where plugins can contribute UI.

Conceptually:
- header
- navigation
- main content
- sidebar
- footer
- other named areas

Plugin injections must be explicit and ordered.

## Default CMS workflows
Immediately after installation, an administrator must be able to:
1. configure basic site settings
2. select/upload/activate a theme
3. create a page
4. create a post
5. upload/manage media
6. create navigation/menu items
7. configure basic SEO
8. publish and view the resulting public website

These workflows must work without installing an optional business plugin.

## Admin
Admin is part of the same application.

It needs:
- authentication
- authorization
- dashboard
- settings
- users/roles
- themes
- plugins
- media
- content
- updates
- system status
- diagnostics

Admin pages provided by plugins must use the same permission system.

## Admin performance
Do not load every plugin's complete UI and assets on every admin page.

Scope assets and services to the current page/feature when practical.

## UX
Installation, plugin/theme management and errors should be understandable to non-developers.

When an action cannot be performed, explain:
- what happened
- why
- what the user can do next

Avoid raw stack traces in normal UI.

## Theme customization
Theme settings should be data/configuration, not source-code editing.

A user should be able to switch presets/settings without modifying theme files.

## Responsive behavior
The default theme/admin foundation should work on common desktop and mobile browsers without requiring a large frontend framework.
