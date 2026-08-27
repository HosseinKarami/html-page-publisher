# HTML Page Publisher

> Publish standalone HTML files — Claude Design exports or any static HTML page — as landing pages at a clean URL on your own WordPress site.

A WordPress plugin for rapidly publishing static HTML landing pages without embedding them in a theme. Built for the "I made this page with an AI tool, now how do I get it onto my site?" moment: it includes purpose-built cleanup for **Claude Design** exports and works with any self-contained HTML page (ChatGPT, Gemini, hand-written). Tools that export React/Vite projects (v0, Bolt) need to be built to static HTML first.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/html-page-publisher.svg)](https://wordpress.org/plugins/html-page-publisher/)
[![Active installs](https://img.shields.io/wordpress/plugin/installs/html-page-publisher.svg)](https://wordpress.org/plugins/html-page-publisher/)
[![Lint](https://github.com/HosseinKarami/html-page-publisher/actions/workflows/lint.yml/badge.svg)](https://github.com/HosseinKarami/html-page-publisher/actions/workflows/lint.yml)

## Features

- Upload a single `.html` file + image assets through a polished admin UI; get a public URL
- **Built-in HTML editor**: edit published pages in the dashboard with the native WordPress code editor (large/minified files fall back to a plain editor automatically)
- **Version history**: every save snapshots the previous version; restore any earlier version with one click (restoring is itself undoable; retention filterable via `htmlpp_max_backups`)
- **Image management**: add, replace, or delete a page's images in place — Replace keeps the original filename so existing references keep working
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
4. Click **Edit** on any page to change its HTML or manage its images; previous versions are saved automatically and can be restored
5. To change the URL prefix, enable subdomain routing, or check storage protection, open **HTML Pages → Settings**

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

## Development

**Requirements:** WordPress 5.9+, PHP 7.4+. Tested up to WordPress 7.1.

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
