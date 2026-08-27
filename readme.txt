=== HTML Page Publisher ===
Contributors: hosseinkarami
Donate link: https://buymeacoffee.com/hosseinkarami?utm_source=wordpress.org&utm_medium=plugin-page&utm_campaign=donate&utm_content=readme-donate
Tags: html, landing page, claude design, ai, static html
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish standalone HTML files — Claude Design exports or any static HTML page — as landing pages at a clean URL on your own WordPress site.

== Description ==

HTML Page Publisher lets you drop a standalone HTML file into WordPress and have it served as a landing page at a clean URL such as `https://example.com/pages/your-slug/`. No theme changes, no FTP, no page builder.

It is built for the "I made this page with an AI tool, now how do I get it onto my site?" moment. It includes purpose-built cleanup for **Claude Design** exports, and works with any self-contained HTML page — from ChatGPT, Gemini, or written by hand. (Tools that export React or Vite projects, such as v0 and Bolt, first need to be built or exported to static HTML.)

**Key features**

* **Upload and publish**: one HTML file plus its images, and you get a public URL
* **Built-in HTML editor**: edit a published page in the dashboard with the native WordPress code editor (syntax highlighting)
* **Version history**: every save keeps the previous version; restore any earlier version with one click (restoring is itself undoable)
* **Image management**: add, replace, or delete a page's images in place — Replace keeps the original filename so existing references keep working
* **Clean Claude Design exports**: strips the export-time runtime wrappers Claude Design adds so the published page is pure static HTML; add rules for other tools with the `htmlpp_sanitizer_rules` filter
* **Configurable URL prefix**: default `/pages/your-slug/`, change to anything you like (e.g. `/resources/`, `/guides/`)
* **Optional subdomain routing**: point `sales.example.com` at your site and pages appear at `sales.example.com/your-slug/`
* **Works on subdirectory installs** (e.g. `example.com/blog/pages/your-slug/`)
* **Cache-friendly**: ETag and Last-Modified headers with conditional (304) and HEAD support, so browsers and CDNs revalidate cheaply while edits still show immediately
* **Protected storage**: pages are only served at their public URL; direct access to the uploads folder and to version-history snapshots is blocked, and Settings shows whether your web server honours the protection
* **Safe by default**: nonce-protected forms, capability checks, path-traversal guards, strict file-extension filtering, and no silent overwrites of live pages
* **Extensible**: actions and filters for add-ons (`htmlpp_loaded`, `htmlpp_page_published`, `htmlpp_before_serve`, `htmlpp_cache_max_age`, `htmlpp_allowed_asset_mimes`, and more)

**Use cases**

* Publishing landing pages generated with Claude Design
* Publishing any AI-generated or hand-written static HTML page
* Sales collateral (rate cards, one-pagers, proposals)
* Campaign landing pages and microsites
* Rapidly publishing static HTML without touching the theme or FTP

HTML Page Publisher is an independent project. It is not affiliated with, endorsed by, or sponsored by Anthropic, OpenAI, Google, Vercel, or StackBlitz. Claude, ChatGPT, Gemini, v0 and Bolt are trademarks of their respective owners.

== Installation ==

1. Upload the `html-page-publisher` folder to `/wp-content/plugins/` (or install from Plugins → Add New).
2. Activate the plugin from the Plugins screen.
3. Go to **HTML Pages** in the admin sidebar to upload your first page.
4. (Optional) Visit **HTML Pages → Settings** to change the URL prefix, configure a subdomain, or check storage protection.

== Frequently Asked Questions ==

= Which AI tools does it work with? =

Any tool that gives you a single, self-contained HTML file. Claude Design's "Export as standalone HTML" is supported directly, including automatic removal of the runtime wrappers it injects. Pages from ChatGPT, Gemini, or written by hand work as-is. Tools such as v0 and Bolt export React/Vite projects rather than static HTML, so you need to build or export those to plain HTML first.

= Where are uploaded pages stored? =

In `wp-content/uploads/html-page-publisher/<slug>/`. Each page has its own directory containing an `index.html` and an `assets/` folder for images. Version-history snapshots are kept separately in `wp-content/uploads/html-page-publisher-backups/<slug>/`. Both folders contain an `.htaccess` that denies direct access, so pages are only reachable at their public URL.

= I use nginx. Is my storage folder protected? =

nginx ignores `.htaccess`. Pages still work at their public URL, but the raw files could also be fetched from the uploads folder. **HTML Pages → Settings** shows whether direct access is blocked and gives you a two-line `location` block to add to your nginx server configuration.

= How do I edit a page or change its images after publishing? =

Open **HTML Pages**, click **Edit** on a page. You get the native WordPress code editor for the HTML, a Version History panel to restore earlier saves, and an Images &amp; Assets panel to add, replace, or delete the page's images without re-uploading the HTML. Use **Replace** (rather than uploading a new file) to swap an image while keeping the same filename, so existing `assets/...` references in your HTML keep working.

= What happens if I upload a slug that already exists? =

The upload is refused with a message explaining why, unless you tick **Replace the existing page**. With the box ticked the new HTML replaces the live page and the previous version is saved to the page's version history. Automated workflows can allow overwrites with the `htmlpp_allow_overwrite` filter.

= Can I lock down editing? =

Yes. The standard `DISALLOW_FILE_EDIT` (or `DISALLOW_FILE_MODS`) constant in `wp-config.php` disables in-dashboard HTML editing, image changes and replacing existing pages, the same way it disables WordPress's core file editor.

= How do I use the subdomain feature? =

Two steps:

1. **DNS**: Add an A or CNAME record pointing your subdomain (e.g. `sales.yourdomain.com`) at the same server as your WordPress site.
2. **Web server**: Configure your hosting to serve that subdomain from the same WordPress install. On shared hosting with cPanel this is typically an "addon domain" or "subdomain" option. On managed hosts, you may need to request it from support.

Then enter the hostname in **HTML Pages → Settings → Subdomain**. Pages will be served at `https://sales.yourdomain.com/your-slug/` in addition to the regular prefix URL.

= Is it safe to upload arbitrary HTML? =

Only users with the `manage_options` capability (administrators by default) can upload pages. Uploaded HTML is served as-is, including any `<script>` tags it contains, so only upload HTML you trust.

= Will uninstalling delete my pages? =

No. Uninstalling removes the plugin's settings but leaves the uploaded pages in `wp-content/uploads/html-page-publisher/` (and their version-history snapshots in `wp-content/uploads/html-page-publisher-backups/`) intact. Delete those folders manually if you want to remove them.

= Does this work with caching plugins or a CDN? =

Yes. Pages are served before WordPress's main query runs, with `ETag` and `Last-Modified` headers; conditional requests get a `304 Not Modified` and `HEAD` requests are supported. The page HTML is sent with `max-age=0, must-revalidate` so edits show immediately; assets get one hour. To let a CDN cache the HTML itself, use the `htmlpp_cache_max_age` filter:

`add_filter( 'htmlpp_cache_max_age', function ( $seconds, $file, $ext, $is_page ) { return $is_page ? 600 : $seconds; }, 10, 4 );`

= Can I add cleanup rules for another export format? =

Yes. Rules are a named array of PCRE pattern/replacement pairs:

`add_filter( 'htmlpp_sanitizer_rules', function ( $rules, $slug, $context ) { $rules['my-tool-banner'] = array( 'pattern' => '/<div class="made-with">.*?<\/div>/is', 'replacement' => '' ); return $rules; }, 10, 3 );`

You can also `unset()` a built-in rule by its key (for example `claude-design-cfasync-script`) if it interferes with your markup.

== Screenshots ==

1. Upload a new page and see everything you have published, with each page's title and public URL.
2. Edit a published page in the dashboard with the native WordPress code editor.
3. Manage a page's images in place and restore any earlier version from Version History.
4. Settings: URL prefix, optional subdomain, and a live check that storage protection is active.

== Changelog ==

= 1.3.0 =
* Security: Pages are now only reachable at their public URL. The storage and version-history folders get an `.htaccess` that denies direct access, and Settings shows whether your web server honours it (with an nginx snippet if it does not).
* New: Real caching headers — `ETag`, `Last-Modified`, conditional `304` responses and `HEAD` support — replacing the previous no-cache headers. Filterable via `htmlpp_cache_max_age` / `htmlpp_cache_control`.
* New: `/pages/slug` now redirects to `/pages/slug/` so relative asset references always resolve, and the page has a single canonical URL.
* New: Uploading to an existing slug no longer silently overwrites the live page. Tick "Replace the existing page" to overwrite; the previous version is kept in the page's history.
* New: Pages list shows each page's `<title>`.
* New: Sanitizer rules are named and filterable (`htmlpp_sanitizer_rules`), and a rule that fails on very large exports can no longer blank the page.
* New: Extension points for add-ons: `htmlpp_loaded`, `htmlpp_upgraded`, `htmlpp_page_published`, `htmlpp_page_updated`, `htmlpp_page_deleted`, `htmlpp_before_serve`, `htmlpp_serve_headers`, `htmlpp_mime_map`, `htmlpp_allowed_asset_mimes`, `htmlpp_allow_overwrite`, `htmlpp_editing_disabled`, `htmlpp_htaccess_rules`, `htmlpp_home_path_candidates`, and an `htmlpp()` accessor. `htmlpp_sanitizer_rules` and `htmlpp_sanitize_html` now also receive the slug and context.
* New: The editor warns before you leave with unsaved changes.
* Security: Version-history snapshots get unguessable filenames and their own protected folder.
* Fixed: Pages are served on subdirectory WordPress installs (e.g. `example.com/blog/`).
* Fixed: Percent-encoded page and asset URLs resolve correctly.
* Fixed: Upload errors caused by PHP size limits are reported with the actual limit instead of a generic failure; per-image failures are listed instead of silently reported as success.
* Fixed: Uploads work on hosts where the WordPress filesystem method is not "direct", and on Windows servers.
* Fixed: Saving the editor after a browser refresh no longer re-submits the form (post/redirect/get).
* Fixed: An editor submission larger than `post_max_size` is refused instead of wiping the page.
* Improved: Admin previews of a page's images load from the main domain, so they work before a subdomain's DNS is live.
* Improved: Readme and admin copy now describe accurately which tools are supported.
* Compatibility: Tested up to WordPress 7.1.

= 1.2.1 =
* Fixed: Other plugins' admin notices no longer render inside the plugin's hero header; they now appear below it via a `.wp-header-end` marker.
* Compatibility: Tested up to WordPress 7.0.

= 1.2.0 =
* New: In-browser HTML editor for published pages, using the native WordPress code editor (syntax highlighting). No FTP required.
* New: Version history — every save snapshots the previous version; restore any earlier version with one click, and restoring is itself undoable. Retention is filterable via `htmlpp_max_backups` (default 10).
* New: Per-page image management — add, replace, or delete a page's images in place. Replace overwrites the original filename so existing HTML references keep working.
* New: Editing respects `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`, mirroring WordPress's core file-editor lockdown.
* Improved: Large or minified files automatically fall back to a plain text editor so the browser stays responsive; the editor is a fixed-height box that scrolls internally.
* Security: Editing and image actions reuse the existing nonce, `manage_options`, MIME-whitelist, and realpath path-traversal protections; backups are stored outside the publicly served directory.

= 1.1.0 =
* New: Branded admin footer with author attribution, support, and donate links.
* New: Contextual Help tab on plugin admin pages (Overview, FAQ, Support).
* New: Settings and Donate links added to the WordPress plugins list (action links and row meta).
* Improved: Refreshed hero design with a custom plugin icon.
* Improved: Cleaner hero buttons with corner radius matching the rest of the admin UI.
* Improved: Settings page icon updated to a clearer sliders icon.
* Improved: Help / Screen Options bar repositioned below the hero for a cleaner layout.
* Improved: Admin footer now flows naturally below content and stays visible on small screens.
* Improved: Microcopy cleanup across admin strings.

= 1.0.0 =
* Initial release.
* Upload HTML + image assets via admin UI.
* Automatic stripping of AI-export runtime wrappers.
* Configurable URL prefix (default `/pages/`).
* Optional subdomain routing.

== Upgrade Notice ==

= 1.3.0 =
Storage folders are now protected from direct access, pages get real caching headers and a canonical URL. Existing pages keep their URLs. One behaviour change: re-uploading to an existing slug now requires ticking "Replace the existing page" instead of overwriting silently.

= 1.2.0 =
Edit published pages and manage their images right in the dashboard, with version history and one-click restore. Fully backward-compatible.
