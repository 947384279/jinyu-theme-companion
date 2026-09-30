=== Jinyu Theme Companion ===
Contributors: jinyu888
Tags: seo, schema, social, related-posts, cache
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion plugin for the Jinyu theme: SEO, structured data, social, related posts, shortcodes, cache, anti-spam. Every outbound feature is off by default.

== Description ==

The companion plugin for the Jinyu (jinyu) WordPress theme. After the theme was split into a three-part structure under the 2026 WordPress.org theme guidelines, this plugin takes over all "functional" capabilities so the theme itself stays a pure presentation layer (no custom post types, shortcodes, or plugin functionality baked in).

The plugin works on any theme: every module guards its theme-specific calls with `function_exists()`, so it degrades gracefully instead of breaking the site.

Features:

* SEO: title optimization, structured data (JSON-LD), category-description SEO, llms.txt, no-category base cleanup
* Indexing / ping: IndexNow and Baidu active submission (both off by default, enabled per site in Settings)
* Social: user follow / unfollow, message center, unread counts
* Content enhancement: related posts, popular posts, the "series" taxonomy, the "moments" (时光圈) custom post type, automatic internal linking, shortcodes with a visual UI, and Web Vitals metrics
* Comments & interaction: comment notifications and anti-spam
* Performance & system: page cache (with path / query-parameter exclusion rules), database optimization, mail (SMTP configuration), HTTP transport check (compression, cache headers, HTTP/3), and post posters
* Performance center: OPcache / Memcached status boards, reversible performance toggles, per-layer cache flushing (OPcache / Memcached / page cache), one-click optimization, and a real-user Web Vitals board
* Theme updates: an optional, off-by-default update channel for the Jinyu theme (see "External Services")
* Third-party login (social login): optional sign-in with GitHub / Gitee / QQ / Apple — off by default. Each provider stores only the OAuth credentials you configure (encrypted at rest); no personal data leaves the site except the standard OAuth exchange when a user signs in.

== Installation ==

1. Upload the plugin via Plugins → Add New, or extract it to wp-content/plugins/jinyu-theme-companion.
2. Enabling the Jinyu theme alongside is recommended for the full experience, but not required.
3. Open the "Jinyu" settings screen and turn on the features you want. Every outbound feature (index ping, theme update check, WeChat share, social login, remote storage, stats) is off by default.

== Frequently Asked Questions ==

= Do I have to use it with the Jinyu theme? =

No. Every module guards its theme-function calls with function_exists(), degrading automatically when the theme is missing. Some UI (for example the ad / subscription front end and the friend-link page) depends on theme templates and will not appear when the plugin runs alone.

= Which features send data externally? =

None by default. Only the features you explicitly enable talk to an external service: index ping (IndexNow, Baidu), the optional theme update check, the WeChat JS-SDK share, third-party login, and the object-storage endpoint you configure yourself. Each one is listed with its terms and privacy policy under "External Services". When a feature is off, no request is made at all.

= Why the jinyu_companion_ prefix? =

To comply with the WordPress.org prefix rules (prefixes must be at least 4 characters and must not collide with other plugins), all functions, constants, options, transients, cookies, shortcodes, scripts and registered post types use a unique prefix: `jinyu_companion_` (or `jinyu_` for the compatibility helpers it shares with the Jinyu theme, which are all wrapped in `function_exists()` guards).

== External Services ==

All connections below are opt-in: they stay completely inactive until an administrator enables the matching feature (and, where relevant, supplies credentials). No request is made while a feature is off.

= 1. Third-party login (social login) — off by default =

When enabled, and only when a visitor chooses to sign in with a provider, the plugin exchanges the OAuth authorization code for an access token and reads the user's basic public profile: account id, display name, avatar, and a verified e-mail address where the provider supplies one. That is the only data transmitted, and it is sent directly to the provider — never to the plugin author.

* GitHub (GitHub, Inc.) — https://github.com/login/oauth/authorize — Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service — Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement
* Gitee (开源中国 OSChina) — https://gitee.com/oauth/authorize — Terms and Privacy: https://gitee.com/terms
* QQ (Tencent) — https://graph.qq.com/oauth2.0/authorize — Terms: https://wiki.connect.qq.com/ — Privacy: https://privacy.qq.com/
* Apple — https://appleid.apple.com/auth/authorize — Terms: https://www.apple.com/legal/internet-services/itunes/ — Privacy: https://www.apple.com/legal/privacy/

Client secrets are encrypted (AES-256-CBC with HMAC) in your site's own database using WordPress salts; they are never transmitted to any party other than the provider they belong to.

= 2. Jinyu theme update service — off by default =

The plugin can check for updates of the Jinyu theme. This is disabled by default and starts only after you enable "Enable theme update check" on the plugin's "Theme Updates" screen. When enabled, and only while the Jinyu theme is active, the plugin periodically requests `https://update.qicaiyun.top/jinyu-update.json` (at most once per hour) to read the latest version number, changelog and package URL. The request contains no site data, no user data and no statistics — it is a plain version lookup. Update packages are verified with an RSA signature and a SHA-256 checksum before WordPress is allowed to install them.

This service is provided by the Jinyu theme author (七彩云博客): Terms and Privacy — https://www.qicaiyun.top/privacy-policy

= 3. WeChat JS-SDK share — off by default =

When the WeChat share feature is enabled, the plugin requests a short-lived access token and JSAPI ticket from `https://api.weixin.qq.com` and loads the WeChat JS-SDK from `https://res.wx.qq.com/open/js/jweixin-1.6.0.js`, so an article shared inside WeChat shows the right title, description and thumbnail. Only your own WeChat Official Account credentials are sent; no visitor data is transmitted.

This service is provided by Tencent (WeChat): Terms — https://weixin.qq.com/agreement?lang=zh_CN — Privacy — https://weixin.qq.com/cgi-bin/readtemplate?lang=zh_CN&t=weixin_agreement&s=privacy

= 4. IndexNow — off by default =

When "Index ping" is enabled, the plugin submits the public URL of a published post to `https://api.indexnow.org/indexnow` so participating search engines can re-crawl it. Only the post URL and your site key are sent — never post content, user data or statistics.

IndexNow is operated by Microsoft Bing: Terms and documentation — https://www.indexnow.org/documentation — Privacy — https://privacy.microsoft.com/privacystatement

= 5. Baidu URL submission — off by default =

When "Index ping" is enabled and you have saved your Baidu submission token, the plugin posts the public URL of a published post to Baidu's URL submission endpoint (`data.zz.baidu.com`). Only the post URL is sent.

This service is provided by Baidu: Terms — https://www.baidu.com/duty/ — Privacy — https://privacy.baidu.com/

= 6. Remote object storage — off by default, you choose the endpoint =

When you configure an object-storage backend (UpYun / Aliyun OSS / Tencent COS / Qiniu / Amazon S3), media files you upload are sent to, and served from, the endpoint you specify. The endpoint is under your control; nothing is sent to the plugin author.

== Privacy ==

This plugin does not collect, store or transmit any personal data by default, and it contains no analytics or telemetry of any kind.

* Index ping (IndexNow / Baidu): only after you enable it, it submits the public URLs of published posts to the corresponding search-engine endpoints. It does not include post bodies or user information.
* Theme update check: only after you enable it. It sends no site data — a plain request for a version file.
* Stats (off by default): if enabled, it records only anonymous aggregate visit counts in your own database. The IP address is used transiently for rate limiting and bot filtering and is not stored with the visit record.
* Social follow / messages: it stores only the follow and message relationships between your site's users in your local database and sends nothing to third parties.
* Third-party login (social login): when enabled and a user signs in with a provider, the plugin sends the standard OAuth parameters to that provider and receives back the user's id, display name, avatar, and verified email (where applicable). No data is shared unless the user initiates a login. Auto-registration only happens when your site has "Anyone can register" enabled, and new accounts always get the site's default role.
* Provider privacy policies are listed in the External Services section.

Administrators can disable any of the above outbound features at any time in the corresponding settings; once disabled, the related requests stop entirely.

== Changelog ==

= 1.2.4 =
* Compliance: the theme update check is now opt-in and off by default (new "Theme Updates" settings screen). The plugin no longer contacts its own update server without the administrator's consent.
* Compliance: removed `Tested up to` from the plugin header (it belongs in this readme only) and removed the redundant `load_plugin_textdomain()` call.
* Compliance: the "moments" (时光圈) post type is now registered as `jinyu_moments`; existing posts are migrated automatically on the next page load and the front-end URLs are unchanged.
* Compliance: replaced the short two-letter `jy_` prefix on transients, cookies and constants with `jinyu_companion_`; the captcha cookie and login-failure counters are renamed accordingly.
* Compliance: social-login auto-registration now requires the site's "Anyone can register" option and always assigns the site's default role, instead of a configurable role that could exceed the site's registration policy.
* Security: sanitized every `$_SERVER` value before use (host, URI, request method, user agent, client IP, conditional-request header), and the nonce passed to `wp_verify_nonce()`.
* Security: JSON-LD now neutralises `</` case-insensitively, so a mixed-case `</SCRIPT>` in a post title or FAQ answer can no longer break out of the script tag.
* Security: shortcode output is escaped with `wp_kses_post()`; the admin notice script is emitted through `wp_print_inline_script_tag()` and its dismiss endpoint now verifies a nonce.
* Fix: the temporary theme update package is now moved with the WP_Filesystem API instead of a raw `rename()` call, and never leaves the WordPress temporary directory.
* Fix: `uninstall.php` and the object-cache drop-in now resolve paths with `wp_upload_dir()` / `WP_CONTENT_DIR` instead of assuming a default `wp-content` layout.
* Fix: the page-cache output buffer is now explicitly closed within the request.
* I18n: the plugin now ships only the `languages/` placeholder; translations are handled by the WordPress.org language packs.

= 1.2.3 =
* Security: Storage pull/push tasks now reject path-traversal keys via a shared `jinyu_storage_safe_rel()` guard (no `..` segments), so a compromised bucket cannot write files outside the uploads directory.
* Security: The poster endpoint no longer reveals draft/pending/private posts to anonymous visitors; non-public content requires login plus the `read_post` capability.
* Security: Page cache bypasses visitors carrying `comment_author_*` cookies, so one commenter's name/e-mail can no longer be prefilled for another visitor.
* Security: Hardened the no-category redirect (path whitelist + `wp_safe_redirect`), the SEO meta REST auth callback (`edit_post` per post), OAuth error messages (URL query strings with tokens are stripped), SMTP `secure` values (whitelist), and the anonymous lazy-comment endpoint (publish check + rate limit).
* Fix: Remote storage prefix handling no longer strips the first character of object keys when the configured prefix is empty.
* Fix: Replaced the deprecated `who=authors` user query; fixed null-access on empty stats rows, the JSON-LD HowTo `$post` scope, an uninitialized batch variable, and platform compatibility (no `GLOB_BRACE`, iframe sandbox without `allow-same-origin`).
* Change: Requires PHP is now 8.0 to match the syntax actually used; on PHP 7.x the social-login module degrades gracefully with an admin notice instead of a fatal error.

= 1.2.2 =
* New: Floating save bar on the settings screen — the panel now tracks unsaved edits the way the theme does. A pill stays pinned to the bottom of the viewport while changes are pending, showing how many fields differ, marking the affected nav items, and offering Save / Discard. Ctrl (Cmd) + S saves and the browser warns before closing a tab with pending edits.
* New: Settings save over `admin-ajax` (`wp_ajax_jinyu_companion_save`) instead of a full-page POST. Saving no longer reloads the page, loses scroll position, or re-renders untouched panes; the existing whitelist sanitising path is unchanged, and the classic full-page submit still works as a fallback.
* New: Image watermark engine — one-time rendering at upload time (before the file reaches object storage) with a nine-point position grid, full-library or selected-attachment batch runs with progress, and a live preview built from the same parameters the engine uses. Renamed originals are kept as `*-jywmo.*` sidecars instead of a second copy on disk.
* New: Media panel gains a watermark section with batch tooling; re-running a batch is idempotent and skipped where a signature is already registered.
* Fix: compatibility with the WordPress 7.1 image editor, where `WP_Image_Editor::width()` / `height()` were removed — sizes now come from `get_size()`.
* Fix: watermark pass no longer writes to a temporary path with a custom suffix; `WP_Image_Editor::get_output_format()` strips unknown extensions, which made the temporary file vanish mid-write.

= 1.2.1 =
* New: Page cache now stores to disk (`wp-content/cache/jinyu/page/`) instead of transients. Transients fall back to `wp_options` on servers without Memcached or Redis, which costs an extra query on every read, writes a large option row on every miss, and risks OOM via autoload. The disk backend needs no extension and no cache daemon: zero SQL, zero resident memory.
* New: Page-cache keys include the version segment, the site's `blog_id`, and a normalized URI — so Multisite and multiple sites on one machine stay isolated (a site can no longer be served another site's page), and the cache dimension can be extended later by bumping the version without any migration.
* New: Conditional requests — a cache hit now sends an ETag and `Cache-Control: public, max-age=600`, and a matching `If-None-Match` returns 304 with no body. Useful when a reverse proxy or CDN sits in front of the site.
* New: Cache-directory diagnostics — if the directory cannot be created or written, the plugin no longer degrades silently. Settings show the exact reason and a copy-paste fix command (correct ownership for the PHP-FPM user), and the admin surfaces a one-time reminder per day.
* New: Non-HTML responses are excluded from caching. Anonymous REST, oEmbed, `_jsonp`, `doing_wp_cron`, `xmlrpc.php` and `admin-ajax.php` are no longer stored as HTML and served back with a Content-Type that does not match the body.
* New: Flushing is now owned by the plugin — `jinyu_companion_cache_flush()` is the single entry point. The legacy `jinyu_cache_flush()` remains as a fallback alias so themes that already define their own copy do not fatal.
* Tweak: no cache read or write while WordPress is installing or upgrading, so a half-built page can never be frozen into a cache file.
* Tweak: if a response has already started, a hit degrades to plain content output instead of discarding the cache entry.

= 1.1.0 =
* New: HTTP transport check on the Front-end Acceleration pane — measures what the plugin cannot configure itself: text compression (HTML vs static assets, checked separately), static-asset cache lifetime, HTML cache headers, and HTTP/3 support. Nothing is requested until you press "Run check"; results are cached for 10 minutes.
* New: Page-cache exclusion rules — "Do not cache these paths" (one per line, directory-prefix and wildcard matching, `#` comments), "Ignored query parameters" (removed from the cache key so one page serves every campaign parameter; defaults to the common utm_* / gclid / fbclid set), and "Do not cache when these parameters are present" (for dynamic or personalized pages).
* Tweak: Page-cache keys are now built from a normalized URI (ignored parameters stripped, remaining ones sorted), so the same page with different tracking parameters reuses a single cache entry. The serve and capture paths share one exclusion check, so nothing is written that can never be read back.
* New: Overview pane adds three tiles — Load Performance (real-user LCP score), Optimization To-dos (click the tile to jump to the pane that needs attention), and Database Health — filling the 4x2 grid.
* Tweak: Overview "Cache hit" tile renamed to "Page cache" (it reports an on/off state, not a hit rate), and the Hero "functional panes" count is now derived from the pane list instead of a hardcoded number.
* Fix: Performance-center status is snapshotted per request, so the overview tile and the performance pane share a single query and the page-load SQL count is unchanged.

= 1.0.5 =
* Fix: comment email notifications never actually went out. The `wp_insert_comment` callback treated its second argument (a WP_Comment object) as the approval value, so every run bailed out at the first check. Reply and post-author notifications are now sent as configured.
* Fix: duplicate emails — when the plugin's post-author notification is enabled, the core "Email me whenever anyone posts a comment" mail is suppressed so one comment sends one email.
* New: Blocked-comment alert for the site admin — emails the admin when a comment is held for moderation or flagged as spam (with author, IP, and a link to the queue; merged into one email within a 15-minute window). Off by default.
* New: Comment-approved notice for the commenter — when a held/spam comment is approved later, the commenter is told their comment is live. Off by default.
* Tweak: comment notification panel hint now states that each notice can be toggled separately.

= 1.0.4 =
* New: Social login — configurable auto-registration gate and default role for new users, replacing the hardcoded Contributor role.
* New: Comment email notification toggles — separately enable/disable reply notifications and post-author notifications.
* New: SMTP "From name" field (falls back to the site name when empty).
* New: IndexNow key is now visible and copyable on the Content pane, along with its verification file URL.
* New: Auto internal-link per-post limit is now configurable (1–20) instead of a hardcoded constant.
* New: Overview pane lists built-in always-on capabilities so they are not mistaken for missing features.

= 1.0.3 =
* New: Third-party login (social login) module — optional sign-in with GitHub / Gitee / QQ / Apple, migrated from the private enhancement plugin so the public companion can provide it independently (WordPress.org plugin-territory compliance). Config lives in its own option; secrets are AES-256-CBC + HMAC encrypted at rest. External Services and privacy disclosures added to this readme.

= 1.0.2 =
* New: Performance center pane — OPcache / Memcached real-time stats, cache flushing, reversible performance toggles and one-click optimization (migrated from the theme so the theme stays presentation-only).

= 1.0.1 =
* WordPress.org compliance hardening: English readme, valid plugin headers (Requires at least / Tested up to / Domain Path), replaced heredoc output with escaped inline PHP, added languages/ domain path, safe core substitutions (wp_strip_all_tags, wp_parse_url), trimmed short description under 150 chars.

= 1.0.0 =
* Initial public release, extracted from the Jinyu theme: SEO / structured data / index ping / social follow and messages / related posts / series / moments / automatic internal linking / shortcodes / anti-spam / page cache / database optimization / mail / posters.

== Upgrade Notice ==

= 1.2.4 =
The theme update check is now opt-in and off by default — enable it on the new "Theme Updates" screen if you want the companion to keep the Jinyu theme up to date. The "moments" posts are migrated to the new `jinyu_moments` post type automatically on the next page load; front-end URLs do not change.

= 1.2.2 =
Adds the floating save bar (no page reload when saving) and the image watermark engine. Watermarking is off by default and runs on new uploads only until you start a batch from the Media pane; original images are preserved as `*-jywmo.*` sidecars. Settings and existing metadata are unaffected.

= 1.2.1 =
Page cache moves to a disk backend and gains conditional requests (ETag / 304), Multisite isolation, and cache-directory diagnostics. If caching was enabled, existing content is served from disk; nothing to configure. Note that a 304 response requires your web server to forward the conditional-request header to PHP — on Nginx add `fastcgi_param HTTP_IF_NONE_MATCH $http_if_none_match;` to the server block. Without it caching still works, you only lose the bandwidth saving. (Apache usually needs no change.)

= 1.1.0 =
Adds the HTTP transport check and page-cache exclusion rules. Existing page-cache settings are preserved; the new "ignored parameters" default only raises the hit rate, it never changes which pages are cached.

= 1.0.0 =
First release: the functional companion plugin split out from the Jinyu theme.

== Resources ==

This plugin no longer depends on any external or theme-provided icon font. All front-end icons (for example the captcha refresh button and the "comment to view" hint) are inlined as self-contained SVG, so the plugin works fully standalone.

* Previously this plugin referenced Font Awesome on the front end; that dependency has been removed. Font Awesome Free remains under the CC BY 4.0 license (https://creativecommons.org/licenses/by/4.0/) and SIL OFL 1.1 (https://scripts.sil.org/OFL); its trademark belongs to Dave Gandy.
