=== Markdown Endpoints for AI ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: markdown, llms.txt, ai agents, ai crawlers, chatgpt
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gives every post and page a clean Markdown version for AI agents and crawlers, with noindex and canonical headers so it never competes with your pages.

== Description ==

AI agents and crawlers read your pages to answer questions and cite sources, but they have to wade through your theme, menus, scripts and ads to find the article. This plugin gives every post and page a clean Markdown version: add .md to any URL, or request the normal URL with an Accept: text/markdown header, and you get the article as structured text with its title, author, dates and canonical link. Fewer tokens for the agent to process means your content is cheaper and easier for AI tools to read and cite, and it never shows up as duplicate content in Google.

= Conversion that works on real sites =

* Converts the rendered page, not the raw database content. Pages built with Elementor, Divi, Beaver Builder, Bricks, WPBakery or shortcodes come out as real text instead of shortcode soup.
* Tables stay real Markdown tables, which matters for the comparison and pricing content AI tools quote.
* Strips the junk: ads, share buttons, newsletter boxes, related posts, cookie banners and tables of contents, plus a list for clutter that's particular to your site.
* Keeps what helps citation: image alt text and captions, links as absolute URLs, footnotes, and code blocks with their language.
* Frontmatter from your existing SEO data: title, description, author, published and modified dates, categories and canonical URL from Yoast SEO, Rank Math, SEOPress or All in One SEO. Nothing new to fill in.
* Facts that only live in schema (product price and rating, FAQ answers, business hours, recipe times) are added as short readable sections.

= SEO safety =

* .md URLs send a canonical Link header pointing at the HTML page and X-Robots-Tag: noindex, so Google never indexes them.
* Header requests get Vary: Accept and are marked private so caches don't hand Markdown to people. A built-in check asks your site for a page as an agent and then as a browser to make sure no page cache or CDN mixes the two up.
* Respects your rules: noindexed, password-protected, private, draft and members-only content is never served, and posts marked with Google's paywall schema only expose their free portion. Pages are always rendered as a logged-out visitor sees them.

= Discovery =

* A link in each page's head (and a Link header) pointing to its Markdown version.
* llms.txt: served by WordPress, or written into your existing llms.txt file between marker lines, with your key pages, the pages AI agents read most and your latest posts.
* A Markdown sitemap listing every .md URL.

= Insights =

* Which AI crawlers and agents fetched which pages, and how often, kept apart from search engines and other bots. Works with Content Powered's AI Crawler Controls plugin.
* Most read by AI: the pages AI agents ask for most, a fair guide to what's feeding AI answers about your topic.
* Token savings: the HTML versus Markdown size of each page.

= Control =

* Choose post types, leave out categories, or switch it off on a single post.
* Content signals: state your preferences on search, AI answers and AI training, site-wide or per post (Content-Signal and Content-Usage headers).
* An optional attribution line at the end of each file, so copied content carries your link.
* Rate limiting for scrapers that hammer your Markdown URLs.

= Fast =

Markdown is built when you publish or update, saved as static files and served from disk. Builds run in the background with Action Scheduler when a plugin on your site provides it, or WP-Cron. A "View Markdown" button in the editor opens a side-by-side preview of the page and what agents get.

Find it under Content Powered > Markdown Endpoints for AI in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `markdown-endpoints-ai` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. That's it: add .md to any post's address to see it. To change anything, go to Content Powered > Markdown Endpoints for AI, and run the caching check on its Overview tab once.

If you use a page cache or CDN, purge it once after activating so cached pages pick up the link to their Markdown version.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Will this create duplicate content in Google? =

No. Every .md response sends X-Robots-Tag: noindex and a canonical Link header pointing at the HTML page, so search engines treat the page as the original. The Markdown sitemap also sends noindex and isn't added to robots.txt.

= Can private or members-only content leak? =

No. Markdown requests are handled as a logged-out visitor before WordPress even looks up the post, so drafts, private posts and anything only logged-in users see never get in. Password-protected, noindexed and members-only posts get a 404, and a cached file is deleted the moment its post stops being public.

= Does it work with my page cache or CDN? =

Yes. Markdown responses tell page caches not to keep them, and .md URLs never collide with your pages. A cache that answers before WordPress runs will give header-based requests the HTML version for pages it already holds, which is harmless. Run the caching check on the Overview tab to see exactly what your setup does.

= My page builder page comes out empty or wrong. What can I do? =

Open the post's "View Markdown" preview. If the wrong part of the page was used, set the content area's CSS selector on the Conversion tab. If something cluttery is left in, add its selector under "Also remove".

= Does it work with Yoast SEO, Rank Math and SEOPress? =

Yes. Descriptions, canonical URLs, noindex settings and primary categories come from whichever is active, and from All in One SEO too. If one of them already makes an llms.txt file, choose "Add a section to my llms.txt file" so both work together.

= Which AI agents does it recognise? =

GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google's AI crawlers, Meta, Amazon, Common Crawl, ByteDance, Mistral, DuckDuckGo, You.com and more. Search engines like Googlebot and Bingbot are counted separately. With Content Powered's AI Crawler Controls active, its list and your own additions are used too.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/markdown-endpoints-ai/releases for the full release history.
