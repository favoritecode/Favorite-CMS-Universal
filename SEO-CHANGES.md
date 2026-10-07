# Automatic SEO and metadata — Favorite CMS Universal 1.1.0

Updated 7 October 2026. The Core version remains 1.1.0, including the post/page sidebar correction. Upload the rebuilt `Favorite-CMS-Universal-v1.1.0-update.zip` through Admin → Core Updates. Manual file-copy installations must run `php migrate.php` to apply migration 019.

## Automatic defaults

- Empty search title uses the content title and site name. Empty description uses excerpt, then readable content, then the site default/title. Generated values are computed when rendering, so older posts work immediately and content edits refresh the fallback. Custom metadata is never overwritten by automatic values.
- The same search defaults reach the default theme, Core fallback templates, Open Graph/Twitter and structured data. Separately supplied social titles/descriptions remain separate from the search title and article headline.
- Summary extraction removes script/style/template/noscript blocks, HTML tags and common shortcode markers, decodes entities, normalizes whitespace and preserves UTF-8 words/graphemes. The roughly 160-character summary is a CMS display heuristic, not a Google character requirement or an AI-written abstract.
- Social images prefer an explicit per-item URL/media override, then featured image, first usable content image and site default. Installation subdirectories are handled consistently. Original content/images are retained.
- The generic post/page SEO panel shows a live approximate search preview and automatic/custom source feedback. It does not fill or rewrite the editable metadata fields. Saved-content review detects duplicate titles/custom descriptions, missing image alt attributes, multiple H1 elements, empty readable content, changed canonical destinations, noindex and recognized broken internal post/page links. Related published posts sharing taxonomies are suggested for manual linking.
- Website and publisher Organization identities accompany existing Article/WebPage/Breadcrumb schema. Article headlines use the content title. Search and 404 pages default to noindex; explicit content indexing directives remain respected.

## Sitemap and old URLs

`/sitemap.xml` includes eligible published content without the old 500-post cap. Larger sites receive a sitemap index with 1,000-URL parts (`/sitemap-1.xml`, etc.). Queries omit full article bodies. Drafts, explicit noindex/none and non-self canonical URLs are omitted; `max-image-preview:none` does not incorrectly exclude a page. XML URLs are escaped and modification dates come from stored content dates. Homepage and categories with published posts are included where appropriate.

A post/page slug changed through its existing model save creates an old-slug mapping to the content ID. Old URLs issue a direct 301 to its current published slug, even after multiple renames. Existing live URLs win; deleted/draft targets are not exposed. The Core updater's already-loaded Database class is supported through explicit migration table-prefix registration. Redirects are stored in a new `seo_redirects` table; per-item social image URLs use an additive `seo_meta.og_image_url` column.

## Separate SEO import/export

Admin → SEO now includes a paginated content audit, export of the current 50-item page, and CSV/JSON metadata upload. For larger batches, repeat across pages or supply up to 500 items / 5 MB per import.

Required columns: `object_type` (`post`/`page`) and at least one of `id`, `slug`, `url`. If multiple identifiers are supplied, they must identify the same existing content. URLs must belong to this installation. Optional fields:

`meta_title`, `meta_description`, `og_title`, `og_description`, `og_image_url`, `canonical_url`, `robots`.

CSV example (UTF-8):

```csv
object_type,id,meta_title,meta_description
post,42,A clear search title,A specific readable description of this article.
page,7,,A description for this page.
```

JSON example:

```json
[{"object_type":"post","id":42,"seo":{"meta_title":"A clear search title","meta_description":"A specific article summary."}}]
```

Blank cells keep current values and automatic fallbacks. Default mode fills only empty fields. An explicit checkbox enables replacement of non-empty metadata, followed by a before/after preview. Changes are committed only after applying that reviewed preview. Invalid rows block the entire import; changed content/metadata invalidates the preview; errors roll back the transaction. Previews expire after 30 minutes. Admin access, POST and CSRF checks apply. Content, slug, publication status, categories and accounts are not changed by SEO-only import. Exported cells neutralize spreadsheet formula prefixes; remove that protective apostrophe if you intend to reimport a literal formula-like title unchanged.

Existing universal JSON content import also accepts a nested `seo` object or the supported flat fields. WordPress WXR import maps literal Yoast/Rank Math title, description, canonical and social fields, plus Yoast noindex. Source-specific title templates containing `%` are skipped so unresolved tokens are not published; automatic fallback remains available. Content import fills empty metadata and reports invalid metadata without discarding the content.

## Verification and limits

- 34 SEO checks passed with an in-memory SQLite fixture and again with an isolated local MySQL/MariaDB database using a table prefix.
- 22 original MySQL/GD integration checks passed, including actual installation with all 19 migrations, editors, draft/history, uploads and backup/restore.
- 17 DOM checks passed, including live automatic/custom SEO preview, non-executing raw-code recovery and existing customizer/keyboard behavior.
- 94 light/dark rendered variants and original form/action comparisons passed. PHP/inline JavaScript syntax and Core ZIP/file-preservation checks passed.
- The actual original v1.0.2 updater passed 10 preservation checks with both new migrations; 9 real HTTP checks passed for login, automatic descriptions, CSV export/upload preview/apply, replay rejection, CSRF/method/authentication and sitemap/search behavior. Reports are in the adjacent `_qa` directory.

No production database or website was connected. Browser screenshot/pixel checks remain unexecuted because no browser surface is connected. The dashboard examines the first 20,000 content characters per item; its checks and internal-link suggestions assist review rather than certify SEO quality. Source-specific SEO template languages, exhaustive external-link checks, full site crawls/orphan detection and Search Console performance data are not implemented. Third-party themes must use the supplied metadata variables/head service to display all defaults.

Automatic metadata prevents missing technical fields; it cannot ensure indexing or top rankings. Content quality and relevance still matter. Google can select different title links/snippets.

References: [Google snippets](https://developers.google.com/search/docs/appearance/snippet), [sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), [Article schema](https://developers.google.com/search/docs/appearance/structured-data/article).