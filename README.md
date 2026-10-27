# Markdown Endpoints for AI

Gives every post and page a clean Markdown version for AI agents and crawlers, at a `.md` URL or through an `Accept: text/markdown` header, with noindex and canonical headers so it never competes with your HTML pages.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

- **Two ways to ask.** `/my-post.md` (and `/index.md` for a static front page; `?markdown=1` without pretty permalinks), or the normal URL with `Accept: text/markdown`.
- **Converts the rendered page, not `post_content`.** The page is rendered by the theme exactly as a logged-out visitor gets it, so Elementor, Divi, Beaver Builder, Bricks, WPBakery and shortcodes come out as real text. The post's content area is found by a marker wrapped around `the_content` during that render (a random value per render, so content can't fake it), then page-builder and theme containers as fallbacks.
- **Markdown that helps citation.** Real GFM tables (alignment, merged cells), nested and task lists, fenced code with its language, image alt text and captions, footnotes (core's Footnotes block and the common plugins), absolute URLs, and YAML frontmatter (title, description, author, dates, categories, tags, canonical) taken from Yoast SEO, Rank Math, SEOPress or All in One SEO.
- **Clutter removed:** ads, share buttons, newsletter boxes and forms, related posts, tables of contents, cookie banners, comments and in-post navigation, plus any selectors you add.
- **Schema carried over:** product prices and ratings, FAQ answers (ones not already on the page), opening hours, recipe times, event details and software ratings become short readable sections.
- **SEO-safe.** `.md` responses send `X-Robots-Tag: noindex` and `Link: <page>; rel="canonical"`. Header-negotiated responses come from the page's own URL, so they send `Vary: Accept` and `Cache-Control: private` instead of noindex (a search engine that asked for Markdown would otherwise drop the real page).
- **Never served:** drafts, private, scheduled and password-protected posts; anything noindexed (SEO plugin, "Discourage search engines", or a robots meta tag in the rendered page); members-only content (Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce Memberships, Ultimate Member, Paid Member Subscriptions); and pages that redirect logged-out visitors. Paywalled posts marked with Google's paywall schema (`isAccessibleForFree: false` with a `hasPart` `cssSelector`) serve only the free portion.
- **Discovery:** `<link rel="alternate" type="text/markdown">` and a matching `Link` header on each page, `/llms.txt` (served, or written into a real llms.txt between marker lines), and `/markdown-sitemap.xml`.
- **Insights:** a request log separating AI crawlers and agents from search and other bots, the pages AI reads most, and HTML-versus-Markdown token savings. Agent names come from Content Powered's AI Crawler Controls when it's active.
- **Control:** post types, excluded categories, a per-post switch, content signals (`Content-Signal` and `Content-Usage` headers, set globally or per post), an attribution line, and per-IP rate limiting.
- **Fast:** each file is built once, saved to disk and served from there until the post, settings, theme or plugins change. Publishing or updating queues a background build (Action Scheduler when a plugin on the site provides it, WP-Cron otherwise).

## How a request is answered

1. `do_parse_request`: a `.md` suffix is taken off `REQUEST_URI` so the normal rewrite rules find the post, and the request becomes a logged-out visitor (`wp_set_current_user( 0 )`) before the main query runs. llms.txt and the sitemap are answered here.
2. `template_redirect` (priority 1): the post is checked against the rules above, the visitor against the rate limit, and a current file is sent from disk (with `ETag` and `304` support).
3. Otherwise the theme renders the page into an output buffer (page caches and optimizers are told to stay away: `DONOTCACHEPAGE`, LiteSpeed's no-cache flag, Autoptimize's filter). On `shutdown` it's converted, saved and sent. A redirect, a noindex tag or a fatal error during that render is remembered, so the page isn't rendered again on every request.

Background builds and the settings page's checks request the site's own URLs with an `X-MDAI-Internal` header signed with HMAC-SHA256 (`wp_salt`), bound to the post and a 10-minute window. It only ever goes to the site's own host.

## Developers

| Hook | Use |
|---|---|
| `markdown_endpoints_ai_markdown` | Change a post's finished Markdown before it's cached. |
| `markdown_endpoints_ai_frontmatter` | Add or remove frontmatter fields. |
| `markdown_endpoints_ai_junk_selectors` | Change the built-in clutter selectors. |
| `markdown_endpoints_ai_why_not` | Return a reason code to keep a post from being served. |
| `markdown_endpoints_ai_is_restricted` | Mark posts as members-only for other membership plugins. |
| `markdown_endpoints_ai_agents` | Add agents to recognise in Insights. |
| `markdown_endpoints_ai_client_ip` | Give the real visitor IP behind a proxy other than Cloudflare. |

Define `MARKDOWN_ENDPOINTS_AI_DISABLED` as `true` in `wp-config.php` to switch everything off.

Selectors (the content area, extra clutter, and paywall `cssSelector`s) support tags, `#id`, `.class`, `[attr]`, `[attr=value]` with `= ~= ^= $= *= |=`, descendant and `>` combinators and comma lists. Anything else is refused.

## Automatic updates

Updates come from https://github.com/jmsprsns/markdown-endpoints-ai through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `markdown-endpoints-ai.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `markdown-endpoints-ai.php` header. It's the only place the version lives. Cached Markdown files rebuild after an update, because the version is part of each file's signature.
2. Commit and push to `main`.
3. Tag that version and push the tag:

   ```bash
   git tag -a v1.0.1 -m "Markdown Endpoints for AI 1.0.1"
   git push origin v1.0.1
   ```

PUC uses the latest GitHub release if there is one, then the highest version tag, then the `Version:` header on `main`, so a tag is enough. Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The description, requirements and "Tested up to" in the "View details" popup come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'MARKDOWN_ENDPOINTS_AI_DEV_MODE', false ); // false = check for updates, true = never check
```

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A fine-grained, read-only token in `MARKDOWN_ENDPOINTS_AI_GITHUB_TOKEN` raises the limit.

## Structure

```
markdown-endpoints-ai.php                       Plugin header, constants, boot, activation
uninstall.php                                   Removes settings, tables, post meta, cached files, jobs, llms.txt section
readme.txt                                      WordPress readme; feeds the "View details" popup
includes/
  class-markdown-endpoints-ai-plugin.php        Main singleton, settings, install, daily clean-up
  class-markdown-endpoints-ai-router.php        Detects Markdown requests, serves files, captures renders
  class-markdown-endpoints-ai-document.php      Finds the content, removes clutter, builds frontmatter and the file
  class-markdown-endpoints-ai-converter.php     HTML to Markdown (DOM based)
  class-markdown-endpoints-ai-selector.php      Strict CSS selector to XPath translator
  class-markdown-endpoints-ai-schema.php        JSON-LD facts sections and paywall markup
  class-markdown-endpoints-ai-seo.php           Yoast SEO, Rank Math, SEOPress and AIOSEO data
  class-markdown-endpoints-ai-access.php        Which posts are served
  class-markdown-endpoints-ai-cache.php         Files on disk, invalidation, background builds, signed loopbacks
  class-markdown-endpoints-ai-discovery.php     Head link, llms.txt (served or written), sitemap
  class-markdown-endpoints-ai-agents.php        AI crawler / bot / tool detection
  class-markdown-endpoints-ai-log.php           Request log, daily totals, rate limiting
  class-markdown-endpoints-ai-diagnostics.php   The caching and endpoints check
  class-markdown-endpoints-ai-editor.php        Block editor panel, classic box, row action, preview page
  class-markdown-endpoints-ai-admin.php         Content Powered > Markdown Endpoints for AI page
  class-markdown-endpoints-ai-menu.php          Shared top-level "Content Powered" menu
  class-markdown-endpoints-ai-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-markdown-endpoints-ai-updater.php       GitHub update checks (PUC wiring)
  class-markdown-endpoints-ai-icons.php         Inline Lucide icon helper
assets/
  admin.css, admin.js                           Settings and preview pages
  editor.js                                     Block editor panel (no build step)
  icon.svg                                      Icon on the update screens
  content-powered.svg                           Sumo logo for the Content Powered menu
icons/                                          The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                          Plugin Update Checker 5.7 library
```

Tables: `{prefix}mdai_log` (recent requests, no IPs, up to 5,000 a day), `{prefix}mdai_daily` (counts per day, page, endpoint and agent), `{prefix}mdai_rate` (per-minute counters keyed by a salted hash of the IP, deleted within minutes). Cached files live in `uploads/markdown-endpoints-ai/{random}/`, with deny rules for Apache.

## The Content Powered menu

Every Content Powered plugin puts its settings page under one shared top-level **Content Powered** menu. Each plugin carries the same `class-markdown-endpoints-ai-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`); the others find it already there and only add their own page.

## Feedback and bug reports

`class-markdown-endpoints-ai-feedback.php` adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu, once however many Content Powered plugins are active (the newest copy of the class by its `VERSION` runs them). Messages go from the site's server to contentpowered.com, one per site every 24 hours. To send them to a local copy of contentpowered.com instead:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Icons

`icons/` holds only the [Lucide](https://lucide.dev/icons) icons the plugin uses. To use another, copy its SVG from the skeleton's full set, then print it with:

```php
echo Markdown_Endpoints_AI_Icons::get( 'file-code', 20 );           // name, size in px
echo Markdown_Endpoints_AI_Icons::get( 'bot', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
