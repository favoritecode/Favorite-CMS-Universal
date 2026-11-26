# Registration correction — deployment and recovery

Versions remain CMS **1.2.1**, Server & License **1.0.1**. These are complete packages, not patches. Use only the owner package on the private control website. Never distribute it to customers.

## Confirmed causes and scope

1. The original packages successfully register three independently installed subdomains in the controlled same-host/IP test. The database primary key is `fcp_installations.id`; neither parent domain, IP nor owner email is a unique key. `site_url` is additionally unique. Dashboard pagination is 20 records and does not group by parent domain.
2. The original client accepts an inherited `secret` as an existing connection without comparing its bound URL to the new canonical URL. A copied connection file therefore continues using the original UUID; signed status did not bind the caller's canonical URL. Reproduction: three original independent sites registered, while a fourth cloned site retained the first UUID and created no new row. This has been fixed and regression-tested.
3. **The specifically reported site `masti.page.gd` has a different confirmed blocker.** A normal server HTTPS request to `/favorite-central-verify` returned HTTP 200, Content-Type `text/html`, and a JavaScript `/aes.js` + `__test` cookie challenge instead of JSON. Opening the website in a browser succeeded with `?i=1`. HTTP 200 is not ownership verification. Its live UUID/database were not inspected, so a live collision is not asserted. This hosting access restriction remains unresolved by software changes. The server now returns `hosting_browser_challenge` and logs a non-secret registration-deferred audit entry. No challenge bypass or unverified auto-approval is implemented.

Provider documentation: https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353 ; staff confirmation for a page.gd API: https://forum.infinityfree.com/t/api-problem/121456 . On the documented free plan this browser check cannot be exempted per route/IP. To meet verified automatic registration, hosting must allow machine-readable server-to-server HTTPS access. Do not weaken ownership checks to conceal this blocker.

## What changes

- New independent identities are cryptographically random UUID v4 values stored in compact 32-hex form. Existing valid 32–64 hex identities remain unchanged.
- Per-installation disk connection state remains below that installation's own root. Storage resolving outside the root is rejected. Local registration is locked; a stale proof created by an interrupted attempt can expire safely.
- The database backup of the ID is scoped by the full canonical URL. Legacy ID backups remain readable. Retries never reset an otherwise valid ID.
- Current canonical URL is checked before accepting an inherited connection. The server verifies the new URL's fresh public ownership proof before creating a separate clone identity. A signed registration binding covers original/assigned ID, URL, nonce, proof hash, credential hash and signing key ID.
- Original records, credentials, purchases and activations remain unchanged during clone separation. Paid activations are not copied. Existing trial dates are copied without resetting the trial. Restricted source identities cannot use this procedure to evade suspension/ban/retirement.
- Missing registry rows and rejected legacy credentials recover using domain ownership proof, preserving the existing URL-bound identity and policy. Network failures, signature failures and failed proof do not cause blind credential deletion.
- Old clients remain protocol-compatible. New clients accept old successful registration for an unchanged ID; clone reconciliation requires the corrected server. Install the owner correction first.
- Status reporting includes last heartbeat. Existing owner-only actions, audited suspend/reactivate, probes, retirement, licensing, signed releases, Google service, SMTP and content controls remain present.
- No schema change is required: schema remains 2. No migration deletes or rekeys customer records. Core version remains 1.2.1. Server correction build is 2026112501.

## Ordinary customer update

1. Back up website files and the database using the existing tools. Retain `.env`, `storage`, uploads, plugins/themes and connection state.
2. Admin → Updates: upload `Favorite-CMS-Universal-v1.2.1-registration-fix.zip`, compare its SHA-256 with the accompanying checksum, validate and install.
3. Open the site/admin in a fresh request. Automatic registration/recovery runs; failed attempts retry with bounded delays. Actual traffic or a scheduler is needed to run PHP; there is no background daemon promised for an idle website.
4. On supported public HTTPS hosting, no user license key, central server field or manual registration is required. Installer disclosure remains in place. A success message is shown only when credentials have actually been issued.
5. Each independent site needs its own document root and installation database namespace/prefix. Two domains pointing to the same physical root and shared `.env` are not two independent installations. Correct that hosting configuration; do not generate random identities per request or trust arbitrary Host headers.

An ordinary update on the same canonical URL preserves its UUID. A deliberate domain move is different from a second independent clone: use the existing authenticated ownership-verified domain transfer procedure and retain the original backup; automatic clone separation does not transfer paid activations from another site.

## Owner deployment — IMPORTANT same-version limitation

The **original supplied Server 1.0.1 updater rejects every same-version ZIP** before applying it. Do not rename a ZIP, edit its version to fake a downgrade/upgrade, or claim it can install this correction normally.

Tested one-time cutover:

1. Take full files + database + private-vault backups; keep them outside the public web root. Record the existing private keys and owner installation identity by protected backup, not by displaying secrets in a report.
2. Verify the final owner ZIP SHA-256. Extract its complete `favorite-server-license` folder at the existing domain root, beside `app` and `bootstrap.php`, replacing its matching server code files. Do not place it in `public`. Do not delete `.env`, `storage`, purchases or private vault files. Avoid a partial extraction while the panel is being used; perform this in a maintenance window or staging first.
3. If an older immutable active runtime exists, open the **existing protected** `/favorite-central-recovery` page. Sign in as the bound Owner and confirm the current password; choose **Restore original installed server code**. After step 2 that verified baseline is the corrected 1.0.1. The existing recovery workflow was tested selecting that complete verified baseline. It does not restore an old database.
4. Open Central Control in a fresh request. Version is still 1.0.1. Then install the corrected CMS ZIP through Admin → Updates on the owner site, and distribute that CMS ZIP to customers.
5. From this corrected server onward, higher correction build numbers at the same semantic version are supported through Server Update. Identical/lower correction builds and lower normal versions are rejected. Owner reauthentication, CSRF, inventory/SHA checks, private backup, atomic activation and rollback remain required.

If hosting forbids this one-time code extraction, the old updater is a genuine deployment blocker. No authentication bypass or unsafe downgrade is supplied.

## Signing key and trust

The previous publisher private key was unavailable. The owner explicitly authorized a new publisher key on 2026-11-25. This build has a new compiled-in public trust anchor and a valid signed 211-file protected-core manifest. This is **not** a signature transition signed by the unavailable previous key. Obtain this complete build through the owner's authenticated official distribution and verify its final ZIP SHA-256. Both new manifest and matching verifier must be installed together by the normal updater.

The new publisher private key is outside both distribution ZIPs. Its encrypted backup is in the separate `owner-private-backup` directory; the recovery key is kept separately in `.publisher-recovery`. Do not publish the recovery key or store it next to its encrypted backup in Git. Existing live central server signing/encryption keys are not rotated by these packages.

Self-hosted PHP is not absolutely tamper-proof. Protected-core reports and signed challenge responses are self-attestation from software controlled by the hosting owner, not independent remote proof of every file.

## Backup and recovery

- Keep the complete pre-update file/database/private-vault backup and new publisher key recovery material. A fresh ZIP does not contain your content or private keys.
- A failed core update uses the existing rollback/recovery paths. If manually restoring old code, restore its matching integrity verifier + manifest as a set; mixing old and new trust files produces a real verification failure.
- Server rollback restores code and retains current databases/purchases. Do not restore an old database over newer orders merely to roll back presentation code.
- Restore the database from backup only for genuine data recovery, understanding that later writes must be reconciled. Do not delete connection files as a repair shortcut.

## Test boundaries / outstanding checks

Passed: full local PHP/MariaDB installers in separate roots/prefixes, three subdomains sharing the same simulated public IP, real central database rows and dashboard HTML, separate-domain fixture, old-to-corrected real updater with content/credential/custom extension retention, clone/recovery/error/signature/policy tests, mocked Google OAuth and authoritative Favorite Pay regressions, signed integrity/tampering tests, server backup/apply/rollback.

Ownership transport in integration tests is explicitly routed through fixture callbacks to each site's actual proof file; public DNS/TLS/CDN/shared-host networking is not claimed by these tests. The actual affected hosting challenge was separately observed. Three real internet hosting deployments, live Gmail consent/delivery, real merchant charges/refunds, and production cutover are not performed. Existing SMTP/PHP Mail code remains byte-for-byte unchanged; no real mail delivery claim is made. The final packages are not represented as making `masti.page.gd` verified while its hosting blocks the proof.
