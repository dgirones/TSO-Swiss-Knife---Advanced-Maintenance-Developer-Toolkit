=== TSO Swiss Knife – Advanced Maintenance & Developer Toolkit ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: maintenance, developer tools, cron, debug, database
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin toolkit with 40+ modules for cron, debug, security, database, redirects, roles, maintenance, staging copies, and site health reports.

== Description ==

TSO Swiss Knife gives WordPress developers and site administrators a single, well-organised panel (under **Tools › TSO Swiss Knife**) to inspect and control the internal systems that affect performance, stability, and security.

= Included modules =

* **Overview** — Landing dashboard with a 0–100 site score built from the Health Report and Security Review checks, the items that need attention (each linked to the right tool), quick vitals, shortcuts and recent activity. Read-only; nothing is sent off-site.
* **Activity History** — Central log of changes across all plugin tools (options edited, database replacements, maintenance mode, admin menu, and more). Pinned as the default favorite for quick access.
* **Hidden WordPress Profiles** — Apply quick presets and toggle safe performance, content, and privacy constants via JSON under the plugin uploads folder (no wp-config.php editing). Runtime filters apply on the next request.
* **Cron Manager** — List scheduled WP-Cron events, run or delete non-core hooks, and keep WordPress core cron events read-only (no manual Run / Edit / Delete).
* **Action Scheduler** — Inspect WooCommerce Action Scheduler tables, pending actions, and queue health when the library is present.
* **Debug Mode** — One-click **Developer mode** for staging that turns on `SAVEQUERIES` for the Slow Query Monitor, saved as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/`. `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY` and `SCRIPT_DEBUG` are set by WordPress before plugins load, so the tab shows their current value and source (read-only) plus copy-paste `wp-config.php` snippets to change them.
* **Options Editor** — Search, inspect, edit, and safely delete `wp_options` rows with core options protected.
* **Meta Editor** — Browse and edit post and user meta. Without an object ID, search matches meta keys only (not values).
* **Option Library** — Save named option presets and re-apply them across environments.
* **Export/Import TSO Configuration** — Back up and restore selected plugin `wp_options` settings as JSON (Redirects, Staging Mode switches, Login Protect, Slow Query settings, and more). Does not include Debug/Security JSON under uploads, the Staging mail log, sandbox sessions, or the Slow Query log.
* **Transients** — Filter by status and purge expired or all transients in bulk (site transients on multisite require a network/super admin).
* **WP Constants** — Read-only overview of relevant constants grouped by category.
* **WP Internals** — Inspect post types, taxonomies, roles, query vars, rewrite tags, and shortcodes.
* **REST API Controls** — Disable anonymous REST API access or block individual namespaces.
* **Heartbeat Controls** — Set Heartbeat mode (default / disable frontend / disable editor / disable all) and interval.
* **Update Manager** — Review pending core, plugin, and theme updates, optionally block update checks (staging), and control update email notifications.
* **Slow Query Monitor** — Log slow database queries when SAVEQUERIES is enabled, inspect live queries for the current request, export CSV/JSON, and open a summary from the admin bar.
* **Search & Replace** — Run dry-run or live serialized-safe search and replace across database tables.
* **Hooks Inspector** — Browse the live `$wp_filter` global, with callback details and a real-time search filter.
* **Rewrite Rules Flush** — Soft or hard flush with a single click; search within the current rules table.
* **Server Files Review** — Scan for unexpected PHP files in uploads and other writable directories; optionally save `robots.txt` / `.htaccess` when you confirm.
* **Redirects** — Manage safe redirect rules stored in the database with import and export support.
* **Custom 404 Page** — Assign a WordPress page as the site 404 response while keeping the original URL and a real HTTP 404 status (no redirect).
* **Slug Manager** — Bulk-edit post and term slugs with conflict detection.
* **Health Report** — Compact checks for risky settings, logs, 404 noise, and related issues; download HTML/JSON in the plugin UI language.
* **Reorder & Hide Sidebar** — Drag to reorder WordPress admin menu items, rename labels, nest items under another section, or hide items for all admins.
* **Users & Sessions** — Review administrators, role-less users, old accounts, and active sessions.
* **Roles & Capabilities** — Compare roles, apply capability templates, and audit dangerous caps.
* **Media Cleaner** — Review unattached media, missing attachment files, and unreferenced uploads.
* **Uploads Disk Footprint** — Scan the uploads folder for size and file-type footprint statistics.
* **Image Sizes Audit** — Review registered image sizes and disable unused sizes where appropriate.
* **Security Review** — Highlight common hardening and update issues.
* **Core File Integrity** — Verify WordPress core files against official checksums and flag unexpected changes.
* **Login Protection** — Custom login URL, brute-force limits, and related hardening controls.
* **Comment Anti-Spam** — Local honeypot/rate-limit rules plus optional reputation or cloud checks (off until you enable them and add keys where required).
* **Email Diagnostics** — Inspect wp_mail settings and send a test email.
* **Staging Mode** — For test copies only (all off by default): red STAGING admin-bar label, ask search engines not to list this copy (without changing Settings → Reading), hold outbound email, pause WP-Cron execution, and keep a short administrator-only mail log in the database (CSV export). Turn everything off before copying the database back to production.
* **URL & HTTPS Doctor** — Explain whether the saved Home and Site addresses still match https, www, and how you opened the admin. Optional one-click loopback check of this site’s own home URL (redirects are not followed), and an optional count of leftover http:// copies of that address. Does not change the database.
* **Server & Runtime** — Read-only view of PHP limits, object-cache drop-in, other wp-content drop-ins, must-use plugins, and optional OPcache reset when the host allows it.
* **Content Audit** — Find hidden content issues such as empty titles, missing thumbnails, duplicate titles, and broken shortcodes. Long slugs are reviewed and fixed in the Slug Manager.
* **Maintenance Mode** — Toggle a 503 maintenance page with a custom message and IP whitelist.
* **Plugin Sandbox** — Isolate plugin conflicts via a must-use loader: only your selected plugins load for your admin session.

= Translations =

* On **Tools › TSO Swiss Knife**, administrators can switch the plugin UI to Catalan (CAT), Spanish (ES), or English (ENG) without changing the site-wide language.
* The same choice applies to this plugin’s AJAX responses and admin downloads (for example Health Report HTML/JSON and URL Doctor messages). It does not change the rest of wp-admin.
* Further locales can be contributed via [Translate WordPress](https://translate.wordpress.org/) once the plugin is published.

== External services ==

This plugin can optionally contact third-party services. None of these calls run unless a site administrator enables the related feature and, where required, provides an API key.

= Comment Antispam (optional) =

When **Comment Antispam** reputation or cloud checks are enabled, visitor data from comments or protected contact forms may be sent as follows:

* **Stop Forum Spam** (`https://api.stopforumspam.org/api`) — Used to look up whether an IP address or an email address has been reported as spam. Read-only anonymous lookups: no account or API key is needed. Sends the visitor public IP and an MD5 hash of the email (never the plain email or the username), only when the option is enabled, once per IP/email every 6 hours. Failed lookups pause for a few minutes and the submission is then allowed. Service: [Stop Forum Spam](https://www.stopforumspam.com/). [Terms of use](https://www.stopforumspam.com/legal) · [Privacy policy](https://www.stopforumspam.com/privacy).
* **AbuseIPDB** (`https://api.abuseipdb.com/api/v2/check`) — Used to check IP reputation. Sends the visitor IP and your AbuseIPDB API key (request header). Service: [AbuseIPDB](https://www.abuseipdb.com/). [Terms of use](https://www.abuseipdb.com/legal) · [Privacy policy](https://www.abuseipdb.com/privacy).
* **CleanTalk** (`https://moderate.cleantalk.org/api2.0`) — Used for cloud spam filtering when CleanTalk mode is selected. Sends your CleanTalk access key plus sender email, IP, nickname, URL, message content, and post/page context. Service: [CleanTalk](https://cleantalk.org/). [Terms of use and privacy policy](https://cleantalk.org/publicoffer).
* **Project Honey Pot (HTTP:BL)** — Optional DNS-based IP reputation lookup using your HTTP:BL access key and the visitor IPv4 address. Service: [Project Honey Pot](https://www.projecthoneypot.org/). [Terms of use](https://www.projecthoneypot.org/terms_of_use.php) · [Privacy policy](https://www.projecthoneypot.org/privacy_policy.php).
* **Akismet** — When cloud mode is set to Akismet and the Akismet plugin is active, spam checks are handled by Akismet according to its own settings and policies. Service: [Akismet](https://akismet.com/). [Terms of service](https://akismet.com/tos/) · [Privacy policy](https://automattic.com/privacy/).

= Core File Integrity (optional) =

When you run a core integrity scan, the plugin requests official WordPress core checksums from `https://api.wordpress.org/core/checksums/1.0/`. Only the WordPress version and locale are sent (no personal data). Service: [WordPress.org](https://wordpress.org/). [Privacy policy](https://wordpress.org/about/privacy/).

= Update Manager language packs (optional) =

When an administrator clicks **Install pending translations** on the Update Manager tab, WordPress downloads language packs from `https://api.wordpress.org/translations/` (via core `Language_Pack_Upgrader`). Locale and package metadata for pending translations are sent; no personal visitor data. This does not run automatically. Service: [WordPress.org](https://wordpress.org/). [Privacy policy](https://wordpress.org/about/privacy/).

= URL & HTTPS Doctor (optional) =

When you click **Check this site**, the plugin requests this site’s own home URL through the WordPress HTTP API (a loopback, similar to Site Health). Redirects are not followed. No third-party host is contacted and no personal data is sent. The request only runs after an administrator clicks the button.

= Health Report security headers (optional) =

Opening **Health Report** (or downloading its HTML/JSON) may request this site’s own home URL with a HEAD request to list common security response headers. Results are cached briefly. No third-party host is contacted and no personal data is sent.

== Installation ==

1. Upload the `tso-swiss-knife-advanced-maintenance-developer-toolkit` folder to `/wp-content/plugins/`, or use **Plugins › Add New › Upload Plugin** with the ZIP.
2. Activate the plugin via **Plugins › Installed Plugins**.
3. Navigate to **Tools › TSO Swiss Knife**.

== Frequently Asked Questions ==

= Does this plugin work with object-cache plugins like Redis? =

Yes. Features that use WordPress cache APIs (for example flushing related caches after cleanup tools) call core functions such as `wp_cache_flush()` / `wp_cache_delete()`, which delegate to whatever persistent object-cache drop-in is active (Redis, Memcached, etc.).

= Is it safe to delete an option from the Options Editor tab? =

The module protects a list of known WordPress core options. For third-party options, verify in your code or database that they are truly unused before deleting.

= Does enabling Maintenance Mode block the admin? =

No. Logged-in administrators are always bypassed, regardless of IP whitelist settings.

= Can I run multiple plugin-testing tools at once? =

Use only the **Plugin Sandbox** in this plugin. Combining it with other per-user plugin override tools may produce unpredictable results.

= Does Update Manager change WordPress auto-updates? =

No. Automatic updates are managed only by WordPress core (**Dashboard → Updates**). Update Manager can block update checks on staging sites, hide specific plugin updates, and control update email notifications — it does not write `auto_update_*` site options or hook `auto_update_*` filters.

= When should I use Staging Mode? =

Use it on a **cloned / staging / local copy**, right after you copy the live site, and before you place test orders or browse as a customer. Enable only the switches you need (badge, noindex, hold email, pause cron, mail log). Turn them all off before you copy that database back to production. Do not leave Staging Mode options enabled on the live site.

= Does Staging Mode change Settings → Reading (“Discourage search engines”)? =

No. It adds noindex headers, pauses XML sitemaps, and adjusts robots.txt while the option is on. It does **not** filter or save `blog_public`, so opening Settings → Reading will not permanently lock “Discourage search engines” after you turn Staging Mode off.

= Does Staging Mode send customer emails from a test copy? =

Not if you enable **Do not send real emails**. WordPress still thinks the mail was accepted, but it never leaves the server. A short copy (recipient, subject, excerpt) is stored in the WordPress database for administrators only (not as a public file under uploads). All Staging Mode switches are off until you turn them on.

= Does pausing scheduled tasks in Staging Mode delete cron events? =

No. Due events stay in **Cron Manager** but are not executed while that Staging Mode option is on. Turn it off on the live site so reminders and queues run again.

= Does URL & HTTPS Doctor change my site address? =

No. It only explains mismatches (http vs https, www, folder, and constants locked in wp-config.php). The leftover-http count is also read-only. Use **Search & Replace** if you decide to rewrite stored URLs, after a backup — always run preview first.

= Can I manually run WordPress core cron events? =

No. Core hooks (for example `wp_version_check` or `wp_maybe_auto_update`) are read-only in Cron Manager: Run, Edit, and Delete are blocked in the UI and in AJAX.

= What does Export/Import TSO Configuration include? =

Selected plugin settings stored in `wp_options` (Redirects, Staging Mode switches, Login Protect, Comment Anti-Spam, Slow Query settings, Health alerts, and similar). It does **not** include Debug/Security/Hidden Profiles JSON under uploads, sandbox sessions, the Staging mail log, or the Slow Query log. Import overwrites the sections you choose; always export a backup first. Login Protect never imports an enabled custom login URL (to avoid lockouts). If Staging switches arrive enabled, confirm you are on a test site.

= Does the CAT / ES / ENG language switcher affect downloads and AJAX? =

Yes for this plugin. Health Report HTML/JSON, URL Doctor messages, and other TSO AJAX/admin-post responses follow the language selected on **Tools › TSO Swiss Knife**. The rest of WordPress admin keeps the site language.

= Where does the plugin write files? =

Runtime config and other managed files go under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/`. The Staging Mode mail log is stored in the database (not under uploads). The Plugin Sandbox may install a must-use loader under `mu-plugins` (via the WordPress Filesystem API) so early plugin filtering can run; that loader is removed when no sandbox sessions remain. The plugin does not write `wp-content/debug.log` or edit `wp-config.php`.

= Does this plugin edit wp-config.php? =

No. Debug flags, security constants, and hidden-profile toggles are saved as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/` and applied at runtime. Constants already defined in `wp-config.php` always take precedence and cannot be overridden from the plugin.

= Does Debug Mode create or manage wp-content/debug.log? =

No. Debug Mode does not create, truncate, or rotate `wp-content/debug.log`. Enabling **Developer mode** only turns on `SAVEQUERIES` (stored as JSON in the plugin uploads config folder); `WP_DEBUG` and `WP_DEBUG_LOG` must be set in `wp-config.php`, and WordPress then writes `debug.log` as usual. The Debug tab can list and preview common log paths when they already exist. Empty and shrink actions apply only to logs the plugin owns under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/` — never to `wp-content/debug.log`.

= Is debug.log safe to leave on a live site? =

Not by default. With `WP_DEBUG_LOG` set to `true`, WordPress writes `wp-content/debug.log`, which is inside the web root under a predictable name. Depending on your server it may be downloadable by URL, and it can contain absolute server paths, SQL queries and, occasionally, credentials or tokens printed by other plugins. This plugin cannot move the log outside the web root: it only saves `WP_DEBUG_LOG` as `true`/`false`, and a log path must be defined in `wp-config.php` before plugins load. Recommended: use Developer mode only on staging, block direct access to `debug.log` at the server level (Apache/nginx rule), or set `define( 'WP_DEBUG_LOG', '/path/outside/webroot/debug.log' );` in `wp-config.php`. Delete or empty the file when you finish debugging. The plugin's own protected folder under uploads (`.htaccess` deny rules) does not cover `wp-content/debug.log`.

= Can Server Files write robots.txt or .htaccess? =

Yes, but only when you explicitly save from the **Server Files Review** module. It can write `robots.txt` and `.htaccess` at the site or WordPress root — not under `wp-content/uploads/`. Always review the generated content before saving on production.

= Who should use Search & Replace or the Options Editor? =

These tools are intended for experienced administrators and developers. Always run **Search & Replace** as a dry-run first and keep a database backup. In **Options Editor**, core options are protected, but deleting or editing third-party options can break plugins or themes. When in doubt, export a snapshot or test on staging.

= Does Comment Antispam send data to third parties? =

Only when you enable reputation or cloud checks and, where required, provide API keys. See the **External services** section above for each provider, what data is sent, and links to their terms and privacy policies. With all cloud features off, checks run locally (honeypot, rate limits, keyword rules, and similar).

= The plugin sees my CDN or proxy address instead of the visitor's IP. What can I do? =

By default the plugin uses the server's `REMOTE_ADDR`, because headers such as `X-Forwarded-For` can be forged when the origin is reachable directly. Behind a CDN or reverse proxy you can opt in by adding `define( 'TSOSK_TRUSTED_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP' );` (or `HTTP_X_FORWARDED_FOR`) to `wp-config.php`, or by returning the header name from the `tsosk_trusted_proxy_header` filter. Visitor IPs are used only for rate limiting, lockouts, whitelists and short-lived duplicate-hit detection (hashed, never stored in clear for the View Counter); they are not sent to any third party unless you enable a cloud antispam provider listed under External services.

= Why do I see two copies of this plugin after installing? =

That usually means the ZIP folder name was wrong (for example `…-main` from a GitHub download instead of `tso-swiss-knife-advanced-maintenance-developer-toolkit`). Remove the duplicate folder under `wp-content/plugins/`, keep only the folder whose name matches the plugin slug, and reactivate.

== Screenshots ==

1. Hidden WordPress Profiles — apply quick presets and toggle performance, content, and privacy constants via the plugin config under uploads (no wp-config.php editing).
2. Custom 404 Page — assign any WordPress page as the site 404 response while keeping the original URL and a real HTTP 404 status (no redirect).
3. Reorder & Hide Sidebar — drag to reorder WordPress admin menu items, rename labels, nest items under another section, or hide items for all admins.
4. Slow Query Monitor — inspect slow and live database queries when SAVEQUERIES is enabled, export the log, and open a summary from the admin bar.

== Changelog ==

= 1.2.3 =
* Fixed: Redirects — "Create redirect" in the 404 Monitor now switches to the Redirects tab, so the form is visible instead of filling it out of sight.
* Improved: Debug Mode lists only the error logs that exist, and new ones appear automatically; it also finds custom WP_DEBUG_LOG paths and error_log files inside plugin and theme folders, works on hosts with symlinked paths and flags logs that cannot be read.
* Fixed: Image Sizes Audit refreshes itself when images are added, deleted or regenerated from anywhere (Media Library, Image Master, other plugins), so it no longer shows outdated counts.
* Fixed: Rewrite Rules — the route simulator now works on subdirectory installs and follows WordPress's own matching (slashes trimmed, unmatched groups left empty, values URL-encoded); a bare domain or "?p=1" is read as the home page.
* Fixed: Rewrite Rules — a hard flush now says whether .htaccess was really updated (and warns when it is read-only, on nginx or on multisite) instead of always reporting success; plain permalinks get their own message. Soft and hard flush can no longer run at the same time.
* Fixed: Server & Runtime — no more PHP warnings or a false "OPcache off" on hosts that restrict the OPcache API (the Reset button is hidden there); the temporary-folder check uses upload_tmp_dir; limits of 0 / -1 are shown as "no limit".
* Improved: Server & Runtime — warns when an object-cache.php drop-in is on disk but WordPress is not using it, and the summary for hosting includes more limits.
* Fixed: Content Audit — the duplicate-titles list no longer cuts its post IDs on MySQL servers (the last one could be wrong) and matches exactly, so "Café" and "Cafe" are no longer reported as the same title.
* Fixed: Content Audit — card headings show the real total ("30 of 412") instead of the size of the capped list; the shortcode inventory no longer lists Markdown links or escaped [[tags]] as shortcodes and keeps their upper/lower case.
* Fixed: Content Audit — removing a broken shortcode no longer touches Markdown links or escaped [[tags]], counts [tag]…[/tag] as one shortcode, and two removals can no longer overwrite each other; it now requires an administrator.
* Improved: Content Audit — the "Long Slugs" card was removed (the Slug Manager already covers it with a configurable threshold, every public post type and bulk fixes) and links to it instead. Scheduled posts are now included, and old pending/private/draft content lists the most neglected items first.
* Fixed: Overview — "Pending updates" now counts language packs too and uses the same numbers as the install button (it showed 0 while the button said 2); the install button moved into the "Needs attention" list as an "updates pending" row, and the list no longer keeps stale plugin/theme update warnings after an install.
* Improved: Overview — visits and downloads show "—" instead of 0 when the View Counter is not counting; the updates row wraps cleanly on phones.
* Fixed: Core File Integrity — an ignored file no longer shows up in both "Modified Files" and "Ignored Files": ignoring or removing a file now takes effect at once, without scanning again, and the lists and counters refresh on their own.
* Improved: Core File Integrity — "Ignored Files" shows whether each file is still flagged; a failed scan keeps the last good result; ignore paths are normalised; dates follow the site time zone.
* Fixed: Update Manager — "Install pending updates" no longer reports language packs as installed when WordPress installed none (the Overview kept showing them and a second press was needed); it now refreshes the offers and retries once, and shows an error if nothing could be installed.
* Fixed: Overview — the progress message of the "updates pending" row sits under the button instead of beside it, so the row no longer jumps while installing; the button keeps its width while it says "Running…".
* Fixed: Overview and Health Report — the debug.log tile and the "debug.log size" check only looked at the log WordPress is writing to, so they said "no file" while the Debug tab listed an older wp-content/debug.log; they now check the same files, name the file when there are several and say "Not readable" when one cannot be read. A memory limit of -1 now reads "-1 (no limit)".

= 1.2.2 =
* Users & Sessions: the "Close" button of a single session never found the session (the table lists WordPress's stored hashes, not the cookie token); it now removes it correctly and logs it. Repeated wrong 2FA codes no longer fill the login history.
* Update Manager: the status table now stacks on phones instead of running out of its card; the Overview and the Update Manager only show the "Install pending updates" button to users who can update both plugins and themes.
* REST API: the "block user listing" and namespace blocks could be bypassed by changing the letter case of the URL (for example /wp-json/WP/V2/Users); routes are now compared case-insensitively.
* Login Protection: the login page served at the custom URL is now excluded from page caches (LiteSpeed and others), which could keep serving it at that address even after the custom URL was turned off.
* Login Protection: a locked-out IP now always gets the lockout message and cannot authenticate through other handlers (before, unknown usernames bypassed the message because core overwrote it); saving is refused when a per-role IP rule would lock out the administrator saving it; IP whitelists and role rules now match IPv6 addresses written in a different notation; more reserved login slugs (wp-json, feed, sitemap…).
* Export/Import configuration: importing the Slow Query Monitor section no longer drops the "capture origin" and "copy to AI plugins" settings.
* Heartbeat: "Disable everywhere" (and the other modes) no longer leave the post editor blank: the Heartbeat script is replaced by an inert stub instead of being unregistered, so the editor scripts that depend on it still load.
* Options Library: option tables now share the same column widths (they were uneven between libraries); previews of very large options no longer load the full value into memory.
* Meta Editor: the search toolbar fields (type, ID, filter, button) are now aligned on one line; the hint no longer shifts the inputs.
* Meta Editor: the list no longer loads the full content of huge meta values just for the preview; values over 1 MB are not opened in the editor; meta keys over 255 characters are rejected when adding.
* Options Editor: sorting and filtering by value type now inspects only the first characters of each value (much faster on large options tables), and options over 1 MB are no longer opened in the editor.
* Options Editor: the list no longer loads the full content of very large options (over 100 KB) just to build a preview, which could exhaust memory or leave the page blank.
* Slow Query Monitor: the plugin/theme origin of a slow query was shifted by the queries WordPress runs before the monitor loads (wrong culprit shown); stored SQL is capped and the log is kept under 2 MB so a single huge query can no longer bloat or break the log; the log lock waits less at the end of a request; the JSON export is no longer corrupted by stray output.
* Heartbeat: an interval outside 15–300 s is now adjusted to the nearest allowed value and the message says so, instead of silently resetting to the WordPress default.
* Cron Manager: the page no longer rescans every plugin's files on each load to find who owns a hook (results are cached and limited to 3 seconds), which could make it slow or blank on sites with many plugins; running an event no longer breaks if its callback prints output or throws an error; rescheduling an event close to its current time no longer fails; stuck scheduled posts of any public post type are listed.
* Hidden WordPress Profiles: post revisions, autosave interval and trash days can be changed again (they were wrongly shown as locked by wp-config.php when WordPress only had its default value); number fields now use the allowed ranges and the trash option warns that 0 disables the trash.
* Slug Manager: the "URL already used" message now names the content type in the site language ("page"/"post" translated).
* Slug Manager: bulk fix now ignores duplicated ids, is capped at 200 posts per request and stops cleanly after 20 seconds (reporting what is left) instead of timing out; shortening a slug can no longer cut a percent-encoded character in half.
* Media Cleaner: unattached media that is in use (featured image, site logo or icon) is now listed apart instead of mixed with the unused ones; the broken-files table no longer repeats the same path or breaks words, and uses red/amber badges as described; the uploads scan processes more files per request, so large libraries finish with far fewer requests.
* Comment Antispam: fixed a bug where blocked comments were still published when the action was "Mark as spam" or "Move to trash" (WordPress overwrote the status); they now really go to spam/trash.
* Comment Antispam: comments posted through Jetpack Comments (iframe form) are no longer rejected by the honeypot/time trap, which cannot exist inside the iframe.
* Comment Antispam: bans and rate limits now skip private/reserved addresses (a proxy or CDN node would otherwise lock out every visitor) and the page warns when a proxy header is detected but not configured.
* CSV exports (Slow Queries, View Counter, Staging mail log, Comment Antispam): output buffers are cleared and the CSV escape character is set explicitly, so a PHP notice or deprecation can no longer end up inside the file; the mail log CSV now includes the UTF-8 BOM for Excel.
* Emails: login verification code, lockout and mass-lockout alerts, security alerts (new/promoted administrator) and the View Counter summary now use the same TSO branded HTML template as the other notifications.
* Redirects: the page is now split into sub-tabs (Redirects, 404 Monitor, Email alerts, Import / Export).
* Fixed: Learning mode was ignored by the WPForms, Gravity Forms, Elementor Pro and Fluent Forms integrations (they blocked anyway); every form integration now respects it, and the hourly spike alert no longer depends on the block log being enabled.
* Added: Comment Anti-Spam can temporarily ban repeat offenders, accepts CIDR ranges in the IP whitelist, can skip external checks for returning commenters, shows a custom message to blocked visitors and lets you put individual rules in observe-only mode.
* Added: Comment Anti-Spam also protects WooCommerce product reviews and the WordPress/WooCommerce registration forms (registration is off by default), shows a blocks-per-day chart, warns when the protection is disabled and ships a larger disposable-email list that also matches subdomains.
* Improved: Comment Anti-Spam page reorganised into four tabs (Overview, Local rules, External services, Logs).
* Improved: Comment Anti-Spam logs now live in their own database table instead of the options table, with a configurable size (up to 10,000 entries) and CSV export; rate-limit and duplicate counters use expiring transients. Existing logs are migrated automatically.
* Added: Comment Anti-Spam email alerts (TSO format) for spam bursts and failing external services, with a test button.
* Improved: Comment Anti-Spam — new "Provider status" card with the last real response and 24 h calls/errors of each external service, plus a Test button per provider.
* Improved: Comment Anti-Spam — blocks-by-reason summary and an "Allow IP" button in the block and learning logs to whitelist false positives.
* Fixed: Comment Anti-Spam — Akismet cloud mode never queried Akismet (it called a method that does not exist) and now uses Akismet's own client.
* Improved: Comment Anti-Spam — Stop Forum Spam lookups are anonymous read-only queries (unaffected by its API-signup restriction); they now send an email hash instead of the plain email, no longer send the username, skip private IPs, and pause for a few minutes after an API error or rate limit.
* Improved: Comment Anti-Spam — remote reputation/cloud checks now run after the cheap local rules, timeouts reduced, and the block log shows readable reasons.
* Improved: Health Report now starts with the "Hide WordPress Site Health notices" card (moved from the Overview) and can also hide the "You should remove inactive plugins / themes" notices.
* Improved: the 404 email alert settings moved from Health Report to a collapsible panel in Redirects, with a "Send test email" button; alerts are sent as a branded HTML email (same look as TSO Link Inspector) listing the most requested missing URLs.
* Fixed: the hourly 404 alert counted batched hits twice, so it could fire before the configured threshold.
* Improved: "Install pending updates" button (plugins, themes and language packs, one at a time) in Update Manager and on the Overview screen; plugins hidden by an individual rule are skipped.
* Improved: Update Manager shows the version offered per plugin and adds an "Update now" button to force a plugin update (even if hidden or checks are blocked).
* Fixed: Update Manager now flags an overdue update-check cron, warns when counts are incomplete because checks are blocked or updates are hidden, includes core in the manual check result and no longer queries wordpress.org twice.
* Fixed: Update Manager "Update now" button no longer ends in a critical error after installing the plugin.

= 1.2.1 =
* Improved: Search & Replace screen reorganised — the preview now appears right under the Preview button, and the table list is collapsed by default with a selected-tables summary.
* Improved: Search & Replace now warns when the replacement is empty (matches get deleted) and when all matches are in internal tables rather than post content.
* Improved: Search & Replace result summary now reads as a proper sentence in every language.
* Fixed: Search & Replace no longer scans or rewrites its own automatic backup, activity log and slow-query log, and the preview now shows the part of the cell where the match is instead of its first characters.
