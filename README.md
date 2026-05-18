# HTML Page Publisher

> Publish HTML landing pages (from Claude Design, ChatGPT, Gemini, v0, Bolt, or hand-written) at a clean, configurable URL.

A WordPress plugin for rapidly publishing static HTML landing pages without embedding them in a theme. Built for the era of AI-generated pages: works seamlessly with output from Claude Design, ChatGPT, Gemini, v0, and Bolt, and with any hand-written static HTML.

## Features

- Upload a single `.html` file + image assets through a polished admin UI
- **Built-in HTML editor**: edit published pages in the dashboard with the native WordPress code editor (syntax highlighting), large/minified files fall back to a plain editor automatically
- **Version history**: every save snapshots the previous version; restore any earlier version with one click (restoring is itself undoable; retention filterable via `htmlpp_max_backups`)
- **Image management**: add, replace, or delete a page's images in place — Replace keeps the original filename so existing references keep working
- **Automatic cleanup of AI-export wrappers**: strips runtime code some AI HTML export tools inject so published pages are pure static HTML
- Configurable URL prefix: default `/pages/your-slug/`, change to anything
- Optional subdomain routing (`sales.example.com/your-slug/`) with DNS pointed at the same server
- Nonce-protected forms, capability checks, path-traversal guards, strict extension filtering; editing respects `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`
- Extensible via the `htmlpp_sanitize_html` filter for other AI export formats

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
4. Click **Edit** on any page to change its HTML or manage its images in the dashboard; previous versions are saved automatically and can be restored
5. To change the URL prefix or enable subdomain routing, open **HTML Pages → Settings**

## Development

**Requirements:** WordPress 5.9+, PHP 7.4+.

### Running locally

Drop this repository into `wp-content/plugins/html-page-publisher/` on a local WordPress install. No build step required.

### Building the distribution ZIP

```bash
./build-zip.sh
```

Produces `html-page-publisher.zip` in the repo root, excluding dev files per [`.distignore`](./.distignore). Upload this ZIP at <https://wordpress.org/plugins/developers/add> (first release) or to your SVN `tags/` directory (subsequent releases).

### Code style

Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). A GitHub Actions workflow runs `php -l` across multiple PHP versions on every push.

## Contributing

Issues and pull requests welcome. Please open an issue before starting on non-trivial changes so we can align on direction.

## License

[GPL-2.0-or-later](./LICENSE).

## Author

[Hossein Karami](https://hosseinkarami.com) · [@HosseinKarami](https://github.com/HosseinKarami)
