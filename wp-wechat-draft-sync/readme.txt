=== 52okp微信发布工具箱 ===
Contributors: 52okp
Tags: wechat, official account, draft, sync
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.5.0
License: AGPLv3 or later

Automatically creates or updates a WeChat Official Account draft whenever a WordPress post is published or updated.

Supports multiple Official Accounts with independent WordPress category routing. An account with no selected categories is excluded from automatic synchronization.

== Installation ==

1. Upload the `wp-wechat-draft-sync` directory to `/wp-content/plugins/`.
2. Activate “WP WeChat Draft Sync”.
3. Open Settings > 公众号草稿同步.
4. Add one or more accounts, enter each AppID/AppSecret and select its automatic-sync categories.
5. Add the WordPress server public IP to each account's WeChat API IP whitelist.
6. Publish a categorized post with a featured image.

== Notes ==

* This plugin only creates drafts. It never broadcasts articles automatically.
* WeChat API access is determined by the account type and permissions shown in the WeChat admin console.
* An unverified personal subscription account may receive API error 48001 if the draft/material APIs are unavailable.
* WP-Cron must be able to run for automatic background synchronization.
* The built-in Moyu Green renderer is adapted from 52okp/gzh-design-skill and its upstream project by Jiamu and Moyu Xiaoli under AGPL-3.0. See THIRD-PARTY-NOTICES.txt.

== Changelog ==

= 1.5.0 =
* Send WordPress-rendered editor content, including Gutenberg blocks and shortcodes, to the tutorial backend.
* Add a batched refresh action for previously synchronized WordPress article bodies.

= 1.4.2 =
* Percent-encode UTF-8 media filenames and article links before sending to the tutorial backend.
* Rebuild failed events from the current WordPress post and supersede queued events with old URLs.

= 1.4.1 =
* Restyle the tutorial sync page to match the publishing console and show per-article backend confirmation IDs.
* Limit historical batches to selected categories, avoid unrelated unpublish tasks, and add a fresh scan cursor.
* Show legacy task deliveries as unverified until the backend confirms an article ID.
* Add a manual queue runner and an exclusion list for articles already present in the legacy tutorial library.

= 1.4.0 =
* Add independent tutorial backend synchronization with a signed queue, category routing, and batched historical import.
* Keep the existing WeChat Official Account draft workflow intact.

= 1.3.1 =
* Allow scheduled posts and AI posts published inside WP-Cron to enqueue automatic synchronization.
* Report scheduling failures instead of showing a misleading queued status.

= 1.3.0 =
* Rename the plugin to 52okp微信发布工具箱.
* Add a standalone dashboard-style publishing console.
* Redesign account and category routing controls as responsive cards.
* Add configuration readiness and routing overview metrics.

= 1.2.0 =
* Add support for multiple WeChat Official Accounts.
* Add per-account WordPress category routing for automatic synchronization.
* Track draft media IDs, success states and errors independently per account.
* Preserve and read the previous single-account configuration during migration.

= 1.1.1 =
* Fix duplicated blank bullets and numbers in WeChat API drafts.
* Replace native UL/OL markup with deterministic Moyu Green list components.
* Omit editor-only leaf attributes from API output.

= 1.1.0 =
* Add deterministic Moyu Green rendering based on the user's modified gzh-design-skill fork.
* Style headings, paragraphs, emphasis, quotes, lists, tables, images and code blocks for WeChat.
* Do not generate the unsupported scrolling TOC, fake buttons or English subtitles.

= 1.0.2 =
* Preserve Gutenberg and Classic Editor code blocks in WeChat drafts.
* Add WeChat-compatible inline styling for block and inline code.

= 1.0.1 =
* Detect image types from file contents instead of URL extensions.
* Convert WebP, AVIF and other unsupported images to JPEG before uploading.

= 1.0.0 =
* Initial release.
