# Favorite CMS — Coding, Testing and Delivery Rules

## Coding principles
Prefer:
- clear names
- small cohesive classes/modules
- explicit interfaces
- dependency injection where useful
- predictable control flow
- typed code where practical
- testable services
- minimal coupling

Avoid:
- giant god classes
- hidden global state
- duplicate implementations
- unnecessary abstraction layers
- framework-like complexity built only for appearance

## Do not over-engineer
Every abstraction must solve a real project requirement.

The CMS needs to be extensible, but extensibility must remain understandable.

## Implementation sequence
Build in this order unless a dependency requires adjustment:

1. application skeleton
2. bootstrap/configuration
3. database/migrations
4. Core services
5. routing/request lifecycle
6. authentication/authorization
7. installer
8. extension foundation
9. rendering engine
10. theme engine
11. admin foundation
12. content/settings/media/storage
13. API
14. update/backup/recovery
15. additional engines
16. example/reference theme
17. example/reference plugin
18. integration/security/performance tests
19. distributable release
20. clean-host install and migration validation

Do not implement a large collection of business plugins before the foundation is stable.

## Testing layers
Use:
- unit tests for isolated logic
- integration tests for service contracts
- feature tests for user workflows
- security tests for authorization/input/upload boundaries
- installation tests
- migration/restore tests
- extension lifecycle tests

## Required workflows to test
At minimum:
- fresh installation
- admin login
- user/role permissions
- content creation/editing
- media upload
- theme install/activate/switch/failure recovery
- plugin install/activate/deactivate/update/uninstall
- dependency failure
- template override
- API authorization
- database migration
- update failure recovery
- backup/restore
- clean-host deployment

## Performance validation
Measure realistic paths:
- homepage
- content listing
- single content page
- admin dashboard
- plugin management
- theme rendering

Watch for:
- N+1 queries
- repeated filesystem scans
- repeated manifest parsing
- unnecessary asset loading
- excessive database queries
- unnecessary bootstrap work

Optimize measured bottlenecks rather than adding speculative caching everywhere.

## Release quality
A release is ready only after:
- tests pass
- security checks pass
- clean installation succeeds
- default site works
- theme/plugin lifecycle works
- backup/restore path is verified
- production error handling is safe
- release ZIP is self-contained
- documentation is sufficient for normal installation and extension development

## Final principle
Build a real product.

The user should be able to install it, create a site, install a theme, add plugins, manage content and users, and move the site to another compatible hosting account without needing to understand the internal architecture.
