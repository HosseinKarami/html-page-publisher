# HTML Page Publisher

> Publish standalone HTML files — Claude Design exports or any static HTML page — as landing pages at a clean URL on your own WordPress site.

A WordPress plugin for rapidly publishing static HTML landing pages without embedding them in a theme. Built for the "I made this page with an AI tool, now how do I get it onto my site?" moment: it includes purpose-built cleanup for **Claude Design** exports and works with any self-contained HTML page (ChatGPT, Gemini, hand-written). Tools that export React/Vite projects (v0, Bolt) need to be built to static HTML first.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/html-page-publisher.svg)](https://wordpress.org/plugins/html-page-publisher/)
[![Active installs](https://img.shields.io/wordpress/plugin/installs/html-page-publisher.svg)](https://wordpress.org/plugins/html-page-publisher/)
[![Lint](https://github.com/HosseinKarami/html-page-publisher/actions/workflows/lint.yml/badge.svg)](https://github.com/HosseinKarami/html-page-publisher/actions/workflows/lint.yml)

## Features

- Upload a single `.html` file + assets, or a whole ZIP bundle (index.html + css/js/images/fonts), and get a public URL
- **Drafts & previews**: hidden from visitors and search engines, shareable preview link with a secret token
- **Custom paths / front page**: serve a page at `/promo/` or as the site's homepage
- **Global snippets**: GA4/GTM/pixels/fonts injected at serve time on every page, per-page opt-out
- **SEO**: core XML sitemap provider, per-page noindex, canonical link; **rename** (301 from the old slug) and **duplicate** (as draft)
- **REST API + WP-CLI**: `POST /wp-json/htmlpp/v1/pages` with an application password, or `wp htmlpp publish`; a Claude Code skill (`skills/publish-to-wordpress`) lets Claude publish what it just built
- **Built-in HTML editor**: edit published pages in the dashboard with the native WordPress code editor (large/minified files fall back to a plain editor automatically)
- **Version history**: every save snapshots the previous version; restore any earlier version with one click (restoring is itself undoable; retention filterable via `htmlpp_max_backups`)
- **File management**: add, replace, or delete a page's images, CSS, JS, fonts and other files in place — Replace keeps the original filename so existing references keep working
- **Clean Claude Design exports**: strips the export-time runtime wrappers Claude Design adds; rules are named and filterable (`htmlpp_sanitizer_rules`)
- Configurable URL prefix (default `/pages/your-slug/`), optional subdomain routing (`sales.example.com/your-slug/`), subdirectory installs supported
- **Cache-friendly**: `ETag` / `Last-Modified`, conditional `304`, `HEAD`; canonical trailing-slash redirect
- **Protected storage**: pages are only reachable at their public URL — the uploads and version-history folders deny direct access, and Settings shows whether your server honours it (with an nginx snippet if not)
- Nonce-protected forms, capability checks, path-traversal guards, strict extension filtering, no silent overwrites; editing respects `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`
- Actions and filters for add-ons (see below)

## Installation

**From the WordPress plugin directory:**

1. Plugins → Add New → search "HTML Page Publisher"
2. Install and activate
3. Go to **HTML Pages** in the admin sidebar

**From this repository:**

1. Download the latest release ZIP from [Releases](https://github.com/HosseinKarami/html-page-publisher/releases)
2. Plugins → Add New → Upload Plugin → select the ZIP
3. Install and activate

## Usage

Full user documentation lives in [`readme.txt`](./readme.txt) (WordPress.org format) or on the plugin's [WordPress.org page](https://wordpress.org/plugins/html-page-publisher/).

Quick start:

1. Activate the plugin → a new **HTML Pages** menu appears
2. Enter a slug, pick an HTML file, optionally add images → Publish
3. Your page is live at `example.com/pages/your-slug/`
4. Click **Edit** on any page to change its HTML, manage its files, set a custom path, or switch between draft and published; previous versions are saved automatically and can be restored
5. To change the URL prefix, enable subdomain routing, add global analytics snippets, or check storage protection, open **HTML Pages → Settings**

## REST API

Base: `https://example.com/wp-json/htmlpp/v1` — authenticate with an [application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for an administrator (`-u "user:xxxx xxxx xxxx xxxx"`), or a logged-in cookie plus `X-WP-Nonce`.

| Method | Route | Body |
| --- | --- | --- |
| `GET` | `/pages` | — |
| `POST` | `/pages` | multipart `slug`, `file` (.html/.zip), `files[]`, `status`, `overwrite` — or JSON `{slug, html, status, overwrite}` |
| `GET` | `/pages/{slug}?html=1` | — |
| `PUT` | `/pages/{slug}` | JSON any of `html`, `status`, `path`, `noindex`, `no_snippets`, `new_slug` |
| `DELETE` | `/pages/{slug}` | — |
| `POST` | `/pages/{slug}/duplicate` | `{new_slug}` |
| `GET` / `DELETE` | `/pages/{slug}/preview-link` | get / reset the draft preview link |
| `GET` / `POST` | `/pages/{slug}/files` | list / multipart `files[]` |
| `DELETE` | `/pages/{slug}/files?reference=assets/hero.png` | or JSON `{reference}` |
| `GET` | `/pages/{slug}/versions` | — |
| `POST` | `/pages/{slug}/versions/{name}/restore` | — |

```bash
curl -u "admin:xxxx xxxx xxxx xxxx" -F slug=spring-promo -F file=@site.zip -F status=draft \
  https://example.com/wp-json/htmlpp/v1/pages
```

`POST /pages` returns `201` for a new page and `200` when `overwrite=true`
replaces one (`created` is `false` then). A duplicate slug without `overwrite`
returns `409 htmlpp_exists`; validation errors return `400`/`409` with a `code`
(`htmlpp_bad_path`, `htmlpp_path_taken`, …) in the standard WP REST error shape.
`files[]` can only be sent as multipart, not JSON.

## WP-CLI

```bash
wp htmlpp list
wp htmlpp publish spring-promo ./index.html --asset=./hero.png
wp htmlpp publish spring-promo ./site.zip --overwrite --porcelain
wp htmlpp update spring-promo --status=published --url-path=promo
wp htmlpp update spring-promo --rename=spring-sale
wp htmlpp preview spring-promo --reset
wp htmlpp versions spring-promo && wp htmlpp restore spring-promo 20260827-171521-ab12cd34.html
```

## Claude Code

The repository is a Claude Code plugin (`.claude-plugin/plugin.json`) with one skill, `publish-to-wordpress`, that walks Claude through publishing a generated page via the REST API with an application password. To use it, copy `skills/publish-to-wordpress` into your project's `.claude/skills/`.

## PHP API

`htmlpp()->pages` is an `HTMLPP_Page_Service` with `list_pages()`, `get()`, `create()`, `update_html()`, `set_meta()`, `rename()`, `duplicate()`, `delete()`, `store_file()`, `delete_file()`, `versions()`, `restore()`, `reset_preview()`. Methods return arrays or `WP_Error`.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `htmlpp_loaded` | action | Plugin bootstrapped; receives the `HTMLPP_Plugin` instance (also available via `htmlpp()`) |
| `htmlpp_upgraded` | action | Version changed; `( $from, $to )` |
| `htmlpp_page_published` | action | New page written; `( $slug, $html )` |
| `htmlpp_page_updated` | action | Existing page overwritten, edited or restored; `( $slug, $html )` |
| `htmlpp_page_deleted` | action | Page removed; `( $slug )` |
| `htmlpp_before_serve` | action | About to stream a file; `( $slug, $file, $rel )` — exit here to gate access |
| `htmlpp_serve_headers` | action | Standard headers sent, body not yet; add `X-Robots-Tag`, CSP, … |
| `htmlpp_sanitizer_rules` | filter | Named PCRE cleanup rules applied on upload/save; `( $rules, $slug, $context )` |
| `htmlpp_sanitize_html` | filter | Final HTML before it is written; `( $html, $slug, $context )` |
| `htmlpp_allow_overwrite` | filter | Let an upload replace an existing slug without the checkbox; `( $allowed, $slug )` |
| `htmlpp_home_path_candidates` | filter | Home-path prefixes tried when routing (subdirectory / multilingual installs) |
| `htmlpp_cache_max_age` | filter | Seconds browsers/CDNs may cache a file (`0` for HTML, `3600` for assets by default) |
| `htmlpp_cache_control` | filter | Full `Cache-Control` header value |
| `htmlpp_mime_map` | filter | Extension → Content-Type map |
| `htmlpp_allowed_asset_mimes` | filter | MIME whitelist for asset uploads |
| `htmlpp_editing_disabled` | filter | Lock down in-dashboard editing |
| `htmlpp_htaccess_rules` | filter | Contents of the protective `.htaccess` |
| `htmlpp_max_backups` | filter | Version-history retention per page |
| `htmlpp_page_meta` | filter | A page's metadata record; `( $record, $slug )` |
| `htmlpp_page_meta_defaults` | filter | Register extra per-page fields an add-on wants persisted |
| `htmlpp_page_meta_updated` | action | Metadata saved; `( $slug, $record, $before )` |
| `htmlpp_page_status_changed` | action | Draft ↔ published; `( $slug, $from, $to )` |
| `htmlpp_page_created` | action | New page (draft or published); `( $slug, $html, $status )` |
| `htmlpp_page_renamed` | action | Files moved to a new slug; `( $old, $new )` |
| `htmlpp_page_copied` / `htmlpp_page_duplicated` | action | Files copied / duplicate draft created; `( $from, $to )` |
| `htmlpp_zip_imported` | action | ZIP unpacked; `( $slug, $import_result )` |
| `htmlpp_assets_uploaded` / `htmlpp_asset_replaced` / `htmlpp_asset_deleted` | action | File changes; `( $slug, … )` |
| `htmlpp_page_html` | filter | Final page HTML before it is sent; `( $html, $slug, $meta, $preview )` |
| `htmlpp_can_preview` | filter | Who may view a draft; `( $allowed, $slug )` |
| `htmlpp_sitemap_entries` | filter | Entries for the XML sitemap |
| `htmlpp_reserved_paths` / `htmlpp_path_collides` | filter | Custom-path validation |
| `htmlpp_home_reserved_query_vars` | filter | Query vars that bypass a front-page mapping |
| `htmlpp_zip_allowed_extensions` / `htmlpp_zip_max_bytes` / `htmlpp_zip_max_files` | filter | ZIP import limits |

## Development

**Requirements:** WordPress 5.9+, PHP 7.4+ (ZIP import needs the ZipArchive extension). Tested up to WordPress 7.1.

### Running locally

Drop this repository into `wp-content/plugins/html-page-publisher/` on a local WordPress install. No build step required.

Try it without installing anything: the plugin ships a [WordPress Playground blueprint](./.wordpress-org/blueprints/blueprint.json).

### Lint and tests

```bash
composer install
composer lint   # WordPress Coding Standards + PHPCompatibility
composer test   # PHPUnit (WordPress-free unit tests for the sanitizer and router)
```

GitHub Actions runs `php -l` on PHP 7.4–8.4, phpcs, and the unit tests on every push and pull request.

### Building the distribution ZIP

```bash
./build-zip.sh
```

Produces `html-page-publisher.zip` in the repo root, excluding dev files per [`.distignore`](./.distignore). Upload this ZIP at <https://wordpress.org/plugins/developers/add> (first release) or to your SVN `tags/` directory (subsequent releases). Screenshots and the Playground blueprint live in [`.wordpress-org/`](./.wordpress-org/) and go to the SVN `assets/` directory.

## Contributing

Issues and pull requests welcome. Please open an issue before starting on non-trivial changes so we can align on direction.

## License

[GPL-2.0-or-later](./LICENSE).

HTML Page Publisher is an independent project, not affiliated with or endorsed by Anthropic, OpenAI, Google, Vercel, or StackBlitz.

## Author

[Hossein Karami](https://hosseinkarami.com) · [@HosseinKarami](https://github.com/HosseinKarami)
