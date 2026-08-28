---
name: publish-to-wordpress
description: Publish a standalone HTML page (a Claude Design export, a generated landing page, or any static HTML file — optionally a ZIP bundle with css/js/images) to a WordPress site that runs the HTML Page Publisher plugin, using its REST API. Use when the user wants an HTML page live on their WordPress site at a clean URL, wants to update or replace a page they published before, or asks to save it as a draft and get a preview link.
---

# Publish an HTML page to WordPress

HTML Page Publisher (free WordPress plugin) serves a standalone HTML file at
`https://example.com/pages/<slug>/` — no theme, no page builder. This skill
drives its REST API (`/wp-json/htmlpp/v1`).

## What you need from the user (ask once, then remember for the session)

1. **Site URL** — e.g. `https://example.com` (the WordPress home URL).
2. **Username** of an administrator account.
3. **Application password** for that user: WordPress → Users → Profile →
   *Application Passwords* → add one named "Claude". It looks like
   `abcd efgh ijkl mnop qrst uvwx` (spaces are fine).
4. The **HTML file** (or a ZIP of the exported folder) and the **slug** the
   page should live at (lowercase letters, digits, hyphens). Suggest a slug
   from the page title if the user has none.

Never print the application password back to the user, and never echo it in a
command whose output is shown. Environment variables do **not** persist between
separate shell commands here, so put the credentials into a file once and read
it from `--netrc-file`/`-K` on each call rather than repeating the secret.

## Step 1 — store the credentials once, then verify access

Write a curl config file (kept out of command output and out of shell history)
and reuse it with `-K` on every request. Do this once per session:

```bash
umask 077
cat > /tmp/htmlpp.curl <<'EOF'
user = "username:abcd efgh ijkl mnop qrst uvwx"
EOF
```

Keep the site URL in the command itself (it is not a secret). Verify access:

```bash
curl -s -K /tmp/htmlpp.curl "https://example.com/wp-json/htmlpp/v1/pages"
```

Delete `/tmp/htmlpp.curl` when you are done.

- `200` with a JSON array → ready.
- `401` → wrong credentials or application passwords disabled (needs HTTPS).
- `404` / HTML instead of JSON → the plugin is not active or REST is blocked;
  tell the user to install **HTML Page Publisher** from Plugins → Add New.

## Step 2 — publish

Single HTML file (assets are optional, repeat `-F 'files[]=@…'`):

```bash
curl -s -K /tmp/htmlpp.curl -X POST "https://example.com/wp-json/htmlpp/v1/pages" \
  -F "slug=spring-promo" \
  -F "file=@./index.html;type=text/html" \
  -F "files[]=@./hero.png"
```

ZIP bundle (index.html + css/js/images/fonts in subfolders; relative paths
are preserved):

```bash
curl -s -K /tmp/htmlpp.curl -X POST "https://example.com/wp-json/htmlpp/v1/pages" \
  -F "slug=spring-promo" -F "file=@./site.zip;type=application/zip"
```

Options: `-F status=draft` publishes as a hidden draft (response includes
`preview_url`); `-F overwrite=true` replaces an existing page (its previous
version is kept in the page's history). Without `overwrite`, an existing slug
returns `409 htmlpp_exists`.

JSON instead of multipart is also accepted:
`{"slug":"spring-promo","html":"<!doctype html>…","status":"published"}`.

The response is the page object: `url`, `status`, `preview_url` (drafts),
`edit_url`, `created` (false when a `overwrite=true` replace), `skipped` (ZIP
entries refused, e.g. `.php`), `file_errors`. A duplicate slug without
`overwrite=true` returns `409 htmlpp_exists`; a validation problem returns a
`400` with a `code` like `htmlpp_bad_path`.

## Step 2b — confirm it renders

Fetch the returned URL and check it comes back as HTML before telling the user
it is live:

```bash
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "https://example.com/pages/spring-promo/"
```

Expect `200 text/html`. **Report `url` (or `preview_url` for drafts) to the
user**, and mention anything in `skipped`/`file_errors`.

## Step 3 — later changes

| Task | Request |
| --- | --- |
| Replace the HTML | `PUT /pages/{slug}` JSON `{"html": "…"}` |
| Publish a draft | `PUT /pages/{slug}` JSON `{"status": "published"}` |
| Serve at `/promo/` or as the front page | `PUT /pages/{slug}` JSON `{"path": "promo"}` or `{"path": "/"}` |
| Hide from search engines | `PUT /pages/{slug}` JSON `{"noindex": true}` |
| Rename (old URL redirects) | `PUT /pages/{slug}` JSON `{"new_slug": "spring-sale"}` |
| Add files | `POST /pages/{slug}/files` multipart `files[]` |
| Delete a file | `DELETE /pages/{slug}/files?reference=assets/hero.png` |
| Version history / restore | `GET /pages/{slug}/versions`, `POST /pages/{slug}/versions/{name}/restore` |
| Preview link / reset it | `GET` / `DELETE /pages/{slug}/preview-link` |
| Delete the page | `DELETE /pages/{slug}` |
| Copy as a new draft | `POST /pages/{slug}/duplicate` JSON `{"new_slug": "…"}` |

## Notes for good results

- Claude Design exports are cleaned automatically (its runtime "tweaks"
  wrappers are stripped). Any self-contained HTML works.
- Reference assets relatively (`assets/hero.png`, `css/site.css`); the plugin
  serves everything under the page's own URL.
- Forms inside static HTML do not submit anywhere by themselves; say so if
  the page contains one.
- Executable files (`.php`, …) are never stored; the API lists them in
  `skipped`.
- WP-CLI is an alternative when you have shell access to the site:
  `wp htmlpp publish spring-promo ./site.zip --overwrite`. Note WP-CLI reserves
  `--path`, so the custom-path flag is `--url-path` (e.g.
  `wp htmlpp update spring-promo --url-path=promo`).
