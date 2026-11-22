# Favorite CMS 1.2.0

Adds the central client, signed updates and repository-managed plugin/theme licensing. CMS and its default theme remain Free. Existing unmanaged extensions remain Free; importing a legacy extension into the repository catalog initially preserves Free access. Only an explicit Owner policy changes that access. Supported new extensions use the configured trial/paid policy.

Extract the Server 1.0.0 ZIP beside app/ and bootstrap.php in the CMS root; it contains only favorite-server-license/ and adds nothing to public/. Apply CMS 1.2.0 through Admin → Updates, then open /favorite-central-setup/ to install/repair the private controls. No second CMS or database is needed. Server includes SMTP/OAuth, license/payment controls and a commit-pinned GitHub extension catalog. Upload catalog ZIPs on main under extensions/plugins/ and extensions/themes/ in Favorite-CMS-Universal; they are never bundled inside the CMS runtime ZIP.

From Server → Repository catalog, refresh, validate/import and open Trial, price & policy. Existing policies, license purchases and activation choices survive package refresh. A paid theme that loses entitlement temporarily renders the Free default without deleting its stored selection/settings. Theme renewals restore the selected theme. Plugin boot is blocked on denied entitlement; stored plugin data is preserved. The central Owner installation remains entitled to its infrastructure.

Private SMTP credentials, signing keys, database and server code are excluded from the public CMS ZIP. Follow INSTALL-BN.md on the server repository, including protected backup and OAuth migration before deleting the old standalone gateway folder.

