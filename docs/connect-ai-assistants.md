# Connect an AI assistant to HTML Page Publisher

From version 1.6, an AI assistant such as Claude or ChatGPT can publish and
edit your HTML pages. It talks to your site through an MCP plugin, which you
install alongside HTML Page Publisher.

## What you need

- WordPress 6.9 or later, and HTML Page Publisher 1.6 or later.
- A site on HTTPS that the assistant can reach. claude.ai and ChatGPT connect
  from the internet, so a site that only runs on your computer won't work with
  them.
- An administrator account for the assistant. Publishing raw HTML needs the
  same rights as in wp-admin (`manage_options` and `unfiltered_html`). A
  separate administrator user for the assistant makes its changes easy to
  spot and its access easy to switch off.
- One MCP plugin. Which one depends on the app you use (see below).

## Pick an MCP plugin for your app

The apps sign in to your site in different ways. claude.ai and ChatGPT only
accept an OAuth sign-in, which WordPress and the official MCP Adapter don't
provide. Desktop and coding apps also accept a WordPress application
password.

| You use | Install | How it signs in |
| --- | --- | --- |
| Claude on the web or phone, or Claude Desktop's **Connectors** | [Easy MCP AI](https://wordpress.org/plugins/easy-mcp-ai/) or [WPVibe](https://wordpress.org/plugins/vibe-ai/) | OAuth sign-in to your site |
| ChatGPT | Easy MCP AI (needs ChatGPT Developer mode) or WPVibe (listed in ChatGPT's apps) | OAuth sign-in to your site |
| Claude Desktop (config file), Claude Code, Cursor, VS Code | [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/), or either plugin above | Application password |

How the three differ:

- **MCP Adapter** is the official plugin from WordPress.org's AI team. It adds
  nothing but the connection, and it only accepts application passwords.
- **Easy MCP AI** runs the connection on your own site, adds an OAuth
  sign-in, and comes with many tools of its own. You choose which tools from
  other plugins it offers (see Option B).
- **WPVibe** sends requests through its own hosted service at wpvibe.ai. It
  stores an encrypted application password there. Its free plan has a daily
  limit on WordPress actions.

## Option A: MCP Adapter with Claude Desktop, Claude Code or Cursor

These steps follow the MCP Adapter's
[guide for site owners](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/getting-started/site-owners.md).

The quickest way is **HTML Pages → Settings → AI assistants**: pick your app
and it installs the MCP Adapter, creates the application password and gives
you the command or config below with your details filled in. To do it by
hand:

1. Install **MCP Adapter** from **Plugins → Add New Plugin**, or click
   **Install MCP Adapter** in **HTML Pages → Settings → AI assistants**.
2. Create an application password: **Users → Profile → Application Passwords**,
   name it (for example "Claude on my laptop") and copy it. WordPress shows
   it only once.
3. Add your site to the app. The address is your site URL followed by
   `/wp-json/mcp/mcp-adapter-default-server`.

**Claude Desktop.** This needs [Node.js](https://nodejs.org) on your
computer. Open **Settings → Developer → Edit Config**, add the entry below
with your values, save, and restart Claude:

```json
{
  "mcpServers": {
    "my-wordpress-site": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

**Claude Code.** Encode `username:application password` in base64, then add
the server:

```bash
printf 'your-username:xxxx xxxx xxxx xxxx xxxx xxxx' | base64 | tr -d '\n'

claude mcp add --transport http my-wordpress-site \
  https://example.com/wp-json/mcp/mcp-adapter-default-server \
  --header "Authorization: Basic PASTE_THE_BASE64_HERE"
```

**Cursor.** Use the same JSON as Claude Desktop in `~/.cursor/mcp.json`.

## Option B: Easy MCP AI with Claude or ChatGPT

1. Install **Easy MCP AI** and open **Easy MCP AI → Dashboard**.
2. Switch on HTML Page Publisher's tools in Easy MCP AI's tool settings. They
   are listed by name (`html-page-publisher/publish-page` and so on).
   Easy MCP AI doesn't turn on another plugin's tools by itself once its list
   is set up, and it never turns on tools that change existing content, such
   as `update-page` and `restore-version`.
3. Connect your app.
   - **Claude:** click **Connect to Claude** on the dashboard, or copy the MCP
     server URL, then in Claude open **Customize → Connectors → Add custom
     connector** and paste it. Sign in to WordPress and approve. The Free
     plan allows one custom connector.
   - **ChatGPT** (Plus, Pro, Business, Enterprise or Education, on the web):
     click **Connect to ChatGPT**, which opens Developer mode. Paste the URL,
     keep Authentication on OAuth, click **Create**, and approve on your site.

## Option C: WPVibe

1. Install **WPVibe**, open its menu in wp-admin, and click **Connect**.
2. Follow the instructions it shows for Claude, ChatGPT or your other app.

WPVibe offers HTML Page Publisher's tools without any setting.

## Try it

Start a new chat and ask, for example:

- "List my HTML pages."
- "Publish this page on my site as a draft at the slug spring-promo." (Attach
  the HTML file, or ask in the chat where the page was made.)
- "Change the headline to 'Spring sale ends Friday', then publish it."
- "Undo your last change to spring-promo."

**HTML Pages → Settings → AI assistants** shows whether WordPress supports
this, whether an MCP plugin is active, and the steps left for your app.

## What the assistant can and can't do

It can list pages, read a page's HTML and files, publish new pages (as drafts
with a preview link if you ask), change part of a page or replace it, publish
or unpublish it, set a custom path or noindex, rename or duplicate it, add
images and other files, and restore an earlier version.

It can't delete pages or files. Every change to a page's HTML keeps the
previous version in the page's history.

To turn the tools off, use the **Offer page tools to AI assistants** switch at
the bottom of **HTML Pages → Settings → AI assistants**, or add this to a small
plugin or your theme:

```php
add_filter( 'htmlpp_register_abilities', '__return_false' );
```

## If it doesn't work

- **The assistant can't find HTML Page Publisher's tools.** Check that the site
  runs WordPress 6.9 or later, that HTML Page Publisher 1.6 or later is active,
  and, with Easy MCP AI, that its tools are switched on.
- **401 Unauthorized.** Check the username and application password. Some
  servers drop the `Authorization` header before WordPress sees it; ask your
  host to pass it through to PHP.
- **"Not allowed" when publishing.** The assistant's user needs to be an
  administrator. On multisite, only network administrators can publish raw
  HTML.
- **claude.ai or ChatGPT can't reach the site.** The site has to be on the
  public internet over HTTPS. Use a staging site rather than a local one.
- **To cut an assistant off:** revoke its application password under
  **Users → Profile**, or its connection in Easy MCP AI or WPVibe.
