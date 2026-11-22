# Favorite CMS Universal 1.1.2

This patch improves GitHub Core updates, same-version repair and the installation success page.

- Missing Core directories pass preflight when their nearest existing parent is writable. Replacement recreates them from the validated package.
- Empty repair inventories retry discovery instead of preventing repair detection for the cache lifetime.
- GitHub's asset SHA-256 digest is used when available, with existing checksum sidecar support retained.
- Remote package versions must match the selected release, and older remote releases cannot replace a newer installation.
- Download limits are enforced during transfer as well as after download.
- Failed update checks and incomplete repair inspection no longer report an unconditional successful check.
- The success-page theme button is separated from the brand, aligned and usable on narrow screens.

## Updating and repairing

Upload this ZIP through Admin > Core Updates, review it and install. The existing backup, maintenance and rollback pipeline still runs before and during replacement. Core repair recreates packaged Core files; it does not recover deleted user uploads, custom themes, plugins, database contents or credentials.

For GitHub automatic updates, publish `Favorite-CMS-Universal-v1.1.2.zip` under release tag `v1.1.2`, together with its `.zip.sha256` file. The package filename, tag, release.json version and bootstrap version must agree. This delivery does not publish to GitHub.

Same-version automatic repair is available when the installed release is also the latest official release and its package can be downloaded and inspected. If GitHub is unavailable, the official ZIP can be uploaded manually.

If deleted files prevent PHP from booting or opening the dashboard (for example bootstrap.php, vendor/autoload.php, the kernel or updater itself), the in-app updater cannot run. Restore those Core files from the official ZIP using the hosting file manager or SFTP, then run the normal update. Keep `.env`, storage, database data, public/uploads and custom plugins/themes intact. A missing `.env` requires the site's own backup; a Core package cannot recreate private credentials.
