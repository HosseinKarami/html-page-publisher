=== HTML Page Publisher ===
Contributors: hosseinkarami
Tags: html, landing page, static html, ai artifact, upload
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload standalone HTML files — including AI-exported artifacts — and publish them as landing pages at a configurable URL.

== Description ==

HTML Page Publisher lets you drop a standalone HTML file (like one exported from a Claude artifact) into WordPress and have it served as a landing page at a clean URL of your choice. Perfect for sales collateral, one-pagers, lead-gen pages, and campaign microsites.

**Key features**

* Simple admin UI: upload one HTML file + its image assets, get a public URL
* **Automatic artifact cleanup** — strips runtime code injected by AI export tools so the published page is pure static HTML
* **Configurable URL prefix** — default `/pages/your-slug/`, change to anything you like (e.g. `/resources/`, `/guides/`)
* **Optional subdomain routing** — point `sales.example.com` at your site and pages appear at `sales.example.com/your-slug/`
* **Secure by default** — nonce-protected forms, capability checks, path-traversal guards, and strict file-extension filtering

**Use cases**

* Sales collateral (rate cards, one-pagers, proposals)
* Campaign landing pages and microsites
* Publishing Claude/AI artifact exports without embedding them into a theme
* Rapid-publishing static HTML without touching the theme or FTP

== Installation ==

1. Upload the `html-page-publisher` folder to `/wp-content/plugins/` (or install from Plugins → Add New).
2. Activate the plugin from the Plugins screen.
3. Go to **HTML Pages** in the admin sidebar to upload your first page.
4. (Optional) Visit **HTML Pages → Settings** to change the URL prefix or configure a subdomain.

== Frequently Asked Questions ==

= Where are uploaded pages stored? =

In `wp-content/uploads/html-page-publisher/<slug>/`. Each page has its own directory containing an `index.html` and an `assets/` folder for images.

= How do I use the subdomain feature? =

Two steps:

1. **DNS**: Add an A or CNAME record pointing your subdomain (e.g. `sales.yourdomain.com`) at the same server as your WordPress site.
2. **Web server**: Configure your hosting to serve that subdomain from the same WordPress install. On shared hosting with cPanel, this is an "addon domain" or "subdomain". On managed hosts (WP Engine, Kinsta, etc.) you may need to request it from support.

Then enter the hostname in **HTML Pages → Settings → Subdomain**. Pages will be served at `https://sales.yourdomain.com/your-slug/` in addition to the regular prefix URL.

= Is it safe to upload arbitrary HTML? =

Only users with the `manage_options` capability (administrators by default) can upload pages. Uploaded HTML is served as-is — including any `<script>` tags it contains — so only upload HTML you trust. The plugin strips runtime code from known AI export tools but does not otherwise filter markup.

= Will uninstalling delete my pages? =

No. Uninstalling removes the plugin's settings but leaves the uploaded pages in `wp-content/uploads/html-page-publisher/` intact. Delete that folder manually if you want to remove them.

= Does this work with caching plugins or a CDN? =

Pages are served via a direct PHP readfile that happens before WordPress's main query runs. Most page caches (WP Rocket, W3 Total Cache) won't cache these URLs by default. If you want them cached at the CDN edge, add the URL pattern to your caching rules — they're plain HTML with cache-friendly headers.

== Changelog ==

= 1.0.0 =
* Initial release.
* Upload HTML + image assets via admin UI.
* Automatic stripping of AI artifact runtime code.
* Configurable URL prefix (default `/pages/`).
* Optional subdomain routing.

== Screenshots ==

1. Upload new page and list existing pages.
2. Settings page for URL prefix and optional subdomain.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
