# Development and regression checks

Production needs PHP/MySQL only. The Core update ZIP intentionally excludes `tests/`, runtime files, credentials, third-party extensions and Node dependencies. The separate tests ZIP contains these test sources and a locked development dependency manifest.

## PHP screen fixtures and DOM behavior

From the CMS directory:

```powershell
php tests/render.php
cd tests/browser
npm.cmd ci --ignore-scripts
$env:CMS_TEST_RENDER_DIR = (Resolve-Path ../renders).Path
npm.cmd run test:dom
```

The renderer uses in-memory SQLite with a MySQL-schema adapter and sample data. It does not connect to the site's configured database. DOM tests use jsdom and simulated network responses; they check form behavior, not rendered pixels. On non-Windows systems use `npm` and your shell's environment-variable syntax.

## Package validation

```powershell
php tests/package-regression.php
```

This creates a unique directory under the system temporary directory and uses an in-memory database. It verifies correct/wrong extension types, archive layouts, manifests, replacement preservation and canonical path extraction. Its fixture path and JSON report are printed.

## Real MySQL/MariaDB and GD integration

Use a disposable local database server and a fresh copied CMS. The test runner rejects the source CMS directory and unmarked directories. It creates randomly named databases; it does not reinstall over an existing site.

```powershell
Copy-Item -Recurse Favorite-CMS-Universal cms-test-copy
Set-Content cms-test-copy/.cms-test-sandbox 'disposable test fixture'
$env:CMS_TEST_ROOT = (Resolve-Path cms-test-copy).Path
$env:CMS_TEST_DB_HOST = '127.0.0.1'
$env:CMS_TEST_DB_PORT = '3306'
$env:CMS_TEST_DB_USER = 'root'
$env:CMS_TEST_DB_PASSWORD = 'your local test password'
php -d extension=gd Favorite-CMS-Universal/tests/integration-mysql.php
```

The account needs permission to create uniquely named test databases. Reports and generated database names are written to the copied CMS's `storage/`. Keep the databases while reviewing the fixture; remove only the exact generated database names when finished. GD can be enabled in the CLI configuration instead of passing `-d extension=gd`.

`tests/core-update-mysql.php` separately tests the original updater: extract the untouched v1.0.2 archive into a marked disposable directory, set `CMS_TEST_ROOT` to that copy, set `CMS_TEST_UPDATE_ZIP` to the new release ZIP and run the script with the same local database environment. It performs the automatic backup, update and preservation checks through the old updater itself. A later fresh HTTP request verifies the newly loaded core.

## Screenshot regression suite

Generate the screen fixtures, then start the read-only fixture server in the CMS directory:

```powershell
php -S 127.0.0.1:8770 tests/render-server.php
```

In another terminal, from `tests/browser`:

```powershell
npm.cmd ci --ignore-scripts
npx.cmd playwright install chromium
$env:CMS_BROWSER_BASE_URL = 'http://127.0.0.1:8770'
npm.cmd run test:baseline
npm.cmd run test:browser
```

Review the first generated screenshots before accepting them as baselines. The suite checks both modes at mobile 390px, tablet 768px and desktop 1440px widths: 47 screens per mode, 282 screenshot combinations, plus customizer interaction checks. It also detects JavaScript errors and page overflow. Use the same browser/OS/fonts for comparisons. See the [official Playwright visual comparison documentation](https://playwright.dev/docs/test-snapshots).

The fixture server serves generated HTML and static CMS assets on localhost; its form endpoints do not modify the site. Actual installation/save/upload/backup behavior is checked by the separate MySQL integration suite. No browser screenshot baseline has been certified in this delivery because no browser surface was connected.

## Building the release

```powershell
php tests/build-release.php
```

This builds a flat-root ZIP with `/` entry paths and validates it using the Core package validator. It omits developer tests and runtime/private files. The delivered release was additionally accepted and installed by the original v1.0.2 validator/updater.
## SEO regression checks

Run `php tests/seo-regression.php` for the in-memory SQLite suite. For a disposable local MySQL server, set `CMS_SEO_MYSQL=1`, `CMS_TEST_DB_PORT`, `CMS_TEST_DB_USER` and `CMS_TEST_DB_PASSWORD`; the suite creates a uniquely named test database and exercises the real migrations with a table prefix. Set `CMS_SEO_TEST_REPORT` to choose the report path. It never connects to a remote database.
