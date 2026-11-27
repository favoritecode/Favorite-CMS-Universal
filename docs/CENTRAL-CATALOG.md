# Favorite CMS 1.2.0

Adds the central client, signed updates and repository-managed plugin/theme licensing. CMS and its default theme remain Free. Existing unmanaged extensions remain Free; importing a legacy extension into the repository catalog initially preserves Free access. Only an explicit Owner policy changes that access. Supported new extensions use the configured trial/paid policy.

Extract the Server 1.0.0 ZIP beside app/ and bootstrap.php in the CMS root; it contains only favorite-server-license/ and adds nothing to public/. Apply CMS 1.2.0 through Admin → Updates, then open /favorite-central-setup/ to install/repair the private controls. No second CMS or database is needed. Server includes SMTP/OAuth, license/payment controls and a commit-pinned GitHub extension catalog. Upload catalog ZIPs on main under extensions/plugins/ and extensions/themes/ in Favorite-CMS-Universal; they are never bundled inside the CMS runtime ZIP.

From Server → Repository catalog, refresh, validate/import and open Trial, price & policy. Existing policies, license purchases and activation choices survive package refresh. A paid theme that loses entitlement temporarily renders the Free default without deleting its stored selection/settings. Theme renewals restore the selected theme. Plugin boot is blocked on denied entitlement; stored plugin data is preserved. The central Owner installation remains entitled to its infrastructure.

Private SMTP credentials, signing keys, database and server code are excluded from the public CMS ZIP. Follow INSTALL-BN.md on the server repository, including protected backup and OAuth migration before deleting the old standalone gateway folder.


# Plugins repository catalog

Upload one complete extension ZIP per file here on main. Use a permanent lowercase ID and semantic version in plugin.json at the ZIP root or inside one enclosing folder. Plugin packages require their normal entry point; themes require index.php. Do not include credentials, runtime storage or databases. Keep releases immutable: different bytes need a new extension version. These ZIPs are distribution artifacts, excluded from the public CMS installer.

Favorite Server and License → Repository catalog automatically checks main when opened (15-minute cache), or use Refresh catalog. Validate & import the package, then open Trial, price & policy. New plugins and themes start with the Owner's default license policy: by default a 7-day free trial (starting when a site first uses the extension), then a license of 200 BDT for 1 year on 1 site is required. Change the default under Central Control → Repository catalog → Default license policy, or change one extension under Plugins & themes → Trial, price & policy. Packages imported earlier keep their current policy until you change it. The Owner chooses Free, Trial + Paid or Paid and configurable price/term/activation/update packages. Refresh and later releases preserve those policies and previous purchases. Removing a source ZIP does not remove installed copies or existing license records.

Public repository ZIPs are publicly downloadable. For paid-source confidentiality use a private CMS repository and a read-only Contents token in Server → Repository catalog → Private repository access. The protected server signs imported releases and enforces download entitlements; a public source archive itself cannot be made private by licensing.

GitHub Releases also work: tag a release `v<version>-<extension-id>` (for example `v1.0.0-favorite-pay`) and attach the package as a `.zip` asset; the id and version inside `plugin.json`/`theme.json` must match the tag. Refresh the catalog in Central Control and use Validate & import. ZIPs written by Windows tools (backslash paths, UTF-8 BOM) are cleaned automatically on import; still save source files as UTF-8 without BOM.
