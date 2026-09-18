# Favorite CMS — Business Capability Rules

## General rule
The CMS must ship as a complete general-purpose CMS.

Built-in baseline:
- posts
- pages
- basic taxonomy
- media
- users/roles/permissions
- menus
- site/content settings
- theme management
- plugin management
- basic SEO

These are not optional plugins.

## Specialized functionality
Specialized business functionality belongs in plugins.

The same CMS must be capable of becoming:
- e-commerce site
- hotel/room booking site
- event/ticket booking site
- subscription/membership site
- specialized multimedia site
- official/company site with only the basic CMS
- other domain-specific websites

## E-commerce capability
The architecture must be capable of supporting plugins for:
- products
- product categories
- carts
- orders
- customers
- payments
- coupons
- inventory
- digital products
- physical products
- subscriptions/memberships
- shipping/tax integrations

Keep payment-provider-specific code behind provider contracts.

## Hotel booking capability
The architecture must support plugins for:
- properties
- rooms/types
- availability
- rates
- guests
- reservations
- booking status
- cancellation rules
- payment integration
- notifications

Do not force hotel concepts into generic Core tables unless they are truly generic infrastructure.

## Ticket/event booking capability
The architecture must support:
- events
- venues
- ticket types
- inventory/capacity
- reservations/orders
- customer information
- ticket status
- payment
- notifications

## Multimedia capability
The architecture must support plugins/themes for:
- video
- audio
- playlists
- media libraries
- series/episodes
- categories
- player pages
- search
- watch/listen pages
- related content
- playlists and queues

The Core provides generic media/storage/rendering primitives; specialized catalog/business rules belong in plugins.

## Extensibility
Future plugins must be able to introduce new content entities, admin pages, routes, APIs and presentation components without changing unrelated Core code.

## Data ownership
Plugin-owned data must have clear ownership and migration rules.

Themes must never become the canonical owner of business data.
