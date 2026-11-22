# Favorite CMS Universal 1.1.4

Based on the supplied 1.1.3 installer. Fixed two unterminated media-card JavaScript strings that prevented the entire Post editor script from initializing, including Save/Publish. Page Save also uses one native submit path instead of triggering a second requestSubmit.

Posts and Pages share a compact Status select + Save button. Draft stays private; Published publishes on Save; Scheduled shows an accessible local date/time picker, site timezone, live summary and Change date & time control. Reopen to reschedule, choose Draft to cancel, or Published to publish now. Moderation options remain for users requiring approval. Schedule values are validated server-side and stored in UTC. Pending/future content is not exposed by native public routes.

Migration 020 adds scheduled_at/scheduled_by to posts/pages and published_at to pages, adds the page Scheduled enum and indexed due-time lookup. Use the normal Core updater so migrations run, or run `php migrate.php` after manually replacing files. Preserve .env, storage, uploads, installed plugins/themes and existing database. The original supplied ZIP is unchanged.

Due publications run before plugins resolve linked routes on each installed-site request. A CLI worker is also supplied for idle sites and predictable publication within a minute:

```cron
* * * * * /usr/bin/php /absolute/path/to/cms/app/Console/publish-scheduled.php
```

Replace both paths with the PHP CLI and CMS path used by your hosting. Without a cron job, overdue content publishes on the next site request. No scheduler can execute while hosting is offline. Concurrent workers use an atomic conditional update and cannot overwrite a newer schedule. Permission is checked again at publication; a revoked/suspended scheduling actor's due item returns to Draft for review.

Validation on an isolated installed MySQL Core: new Post/Page creation, canonical content saved, Draft/Published/Scheduled, timezone conversion (Asia/Dhaka → UTC), public visibility boundaries, rescheduling, cancellation, due-request publication, idempotent worker, invalid/past dates, status whitelist and moderation/page permissions. Browser inline scripts compile, editor flows have no JS errors. Migration repeat-safety and package lint/integrity checked. Hosting itself has not been changed.
