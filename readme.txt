=== Plugin Name ===
Jinyu Theme Companion

Contributors: qicaiyun
Tags: seo, schema, social, related-posts, cache
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official companion for the Jinyu theme: SEO, structured data, social, related posts, shortcodes, cache, anti-spam. Outbound features off by default.

== Description ==

The official companion plugin for the Jinyu (jinyu) WordPress theme. After the theme was split into a three-part structure under the 2026 WordPress.org theme guidelines, this plugin takes over all "functional" capabilities so the theme itself stays a pure presentation layer (no custom post types, shortcodes, or plugin functionality baked in).

Features:

* SEO: title optimization, structured data (JSON-LD), category-description SEO, llms.txt, no-category base cleanup
* Indexing / ping: IndexNow and Baidu active submission (both can be turned off in settings)
* Social: user follow / unfollow, message center, unread counts
* Content enhancement: related posts, popular posts, the "series" taxonomy, the "moments" custom post type, automatic internal linking, shortcodes with a visual UI, and Web Vitals metrics
* Comments & interaction: comment notifications and anti-spam
* Performance & system: page cache, database optimization, mail (SMTP configuration), and post posters
* Third-party login (social login): optional sign-in with GitHub / Gitee / QQ / Apple — off by default. Each provider stores only the OAuth credentials you configure (encrypted at rest); no personal data leaves the site except the standard OAuth exchange when a user signs in.

* Performance center (migrated from the theme): OPcache / Memcached status boards, reversible performance toggles, per-layer cache flushing (OPcache / Memcached / page cache), one-click optimization, and real-user Web Vitals board

This plugin works best when paired with the Jinyu theme. When the theme is not active, every feature degrades gracefully and will not white-screen the site.

== Installation ==

1. Upload the plugin via Plugins → Add New, or extract it to wp-content/plugins/jinyu-theme-companion.
2. Enabling the Jinyu theme alongside is recommended for the full experience.
3. Enable outbound features such as index ping and stats in Settings as desired (all are off by default).

== Frequently Asked Questions ==

= Do I have to use it with the Jinyu theme? =

No. Every module guards its theme-function calls with function_exists(), degrading automatically when the theme is missing. Some UI (for example the ad / subscription front end and the friend-link page) depends on theme templates and will not appear when the plugin runs alone.

= Which features send data externally? =

Only index ping (IndexNow, Baidu) and optional stats send URLs or basic visit data after being enabled. These are off by default and must be turned on by the administrator; when off, no outbound requests are made.

= Why the jinyu_ prefix? =

To comply with the WordPress.org plugin prefix rule (at least 4 characters and not conflicting with others), the jinyu_ prefix is used consistently, matching the Jinyu theme.

== External Services ==

The optional "Third-party Login (Social Login)" feature connects to external OAuth providers only when it is enabled AND a visitor uses it to sign in. No requests are made unless both conditions are true.

For each enabled provider, the plugin exchanges the OAuth authorization code for an access token and retrieves the user's public profile (id, display name, avatar, and a verified email when the provider supplies one). Only the data the OAuth protocol requires is transmitted.

* GitHub — https://github.com/login/oauth/authorize ; Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement
* Gitee — https://gitee.com/oauth ; Privacy: https://gitee.com/terms/privacy
* QQ — https://graph.qq.com/oauth2.0/authorize ; Privacy: https://privacy.qq.com/
* Apple — https://appleid.apple.com/auth/authorize ; Privacy: https://www.apple.com/legal/privacy/

Client secrets are encrypted (AES-256-CBC with HMAC) in your site's own database using WordPress salts; they are never transmitted to any party other than the provider they belong to.

== Screenshots ==

(Add screenshots before release: settings page, related posts, message center, shortcode UI, and so on.)

== Changelog ==

= 1.0.4 =
* New: Social login — configurable auto-registration gate and default role for new users (Subscriber / Contributor / Author), replacing the hardcoded Contributor role.
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

= 1.0.0 =
First release: the functional companion plugin split out from the Jinyu theme.

== Resources ==

This plugin uses Font Awesome icons (CSS class fa-*) on the front end; the fonts and styles are loaded and distributed by the Jinyu theme.

* Font Awesome Free: icons are under the CC BY 4.0 license (https://creativecommons.org/licenses/by/4.0/), and fonts are under the SIL OFL 1.1 license (https://scripts.sil.org/OFL).
* Trademark: Font Awesome is a trademark of Dave Gandy. This plugin uses it only under its open-source license and is not affiliated with the trademark holder.

== Privacy ==

This plugin does not collect or upload any personal data by default.

* Index ping (IndexNow / Baidu): only after you enable it, it submits the public URLs of published posts to the corresponding search-engine endpoints. It does not include post bodies or user information.
* Stats (off by default): if enabled, it records only anonymized basic visit counts and does not record personal identity beyond the IP address.
* Social follow / messages: it stores only the follow and message relationships between your site's users in your local database and sends nothing to third parties.
* Third-party login (social login): when enabled and a user signs in with a provider, the plugin sends the standard OAuth parameters to that provider and receives back the user's id, display name, avatar, and verified email (where applicable). No data is shared unless the user initiates a login. Provider privacy policies are listed in the External Services section.

Administrators can disable any of the above outbound features at any time in the corresponding settings; once disabled, the related requests stop.
