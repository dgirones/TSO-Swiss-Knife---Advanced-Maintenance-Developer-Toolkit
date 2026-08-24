=== TSO Swiss Knife – Advanced Maintenance & Developer Toolkit ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: maintenance, developer tools, cron, debug, database
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin toolkit with 35+ modules for cron, debug, security, database, redirects, roles, maintenance, staging copies, and site health reports.

== Description ==

TSO Swiss Knife gives WordPress developers and site administrators a single, well-organised panel (under **Tools › TSO Swiss Knife**) to inspect and control the internal systems that affect performance, stability, and security.

= Included modules =

* **Activity History** — Central log of changes across all plugin tools (options edited, database replacements, maintenance mode, admin menu, and more). Pinned as the default favorite for quick access.
* **Hidden WordPress Profiles** — Apply quick presets and toggle safe performance, content, and privacy constants via JSON under the plugin uploads folder (no wp-config.php editing). Runtime filters apply on the next request.
* **Cron Manager** — List, manually run, or delete scheduled WP-Cron events. Core hooks are protected from accidental deletion.
* **Action Scheduler** — Inspect WooCommerce Action Scheduler tables, pending actions, and queue health when the library is present.
* **Debug Mode** — One-click **Developer mode** preset for staging (`WP_DEBUG`, `WP_DEBUG_LOG`, `SAVEQUERIES`; errors hidden from visitors), saved as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/`. The tab shows the constants currently in effect (read-only) and copy-paste `wp-config.php` snippets. Constants already defined in `wp-config.php` cannot be overridden.
* **Options Editor** — Search, inspect, edit, and safely delete `wp_options` rows with core options protected.
* **Meta Editor** — Browse and edit post and user meta with type-aware validation.
* **Option Library** — Save named option presets and re-apply them across environments.
* **Export/Import TSO Configuration** — Back up and restore plugin settings and module preferences as JSON.
* **Transients** — Filter by status and purge expired or all transients in bulk.
* **WP Constants** — Read-only overview of relevant constants grouped by category.
* **WP Internals** — Inspect post types, taxonomies, roles, query vars, rewrite tags, and shortcodes.
* **REST API Controls** — Disable anonymous REST API access or block individual namespaces.
* **Heartbeat Controls** — Set Heartbeat mode (default / disable frontend / disable editor / disable all) and interval.
* **Update Manager** — Review pending core, plugin, and theme updates, optionally block update checks (staging), and control update email notifications.
* **Slow Query Monitor** — Log slow database queries when SAVEQUERIES is enabled, inspect live queries for the current request, export CSV/JSON, and open a summary from the admin bar.
* **Search & Replace** — Run dry-run or live serialized-safe search and replace across database tables.
* **Hooks Inspector** — Browse the live `$wp_filter` global, with callback details and a real-time search filter.
* **Rewrite Rules Flush** — Soft or hard flush with a single click; search within the current rules table.
* **Server Files Review** — Scan for unexpected PHP files in uploads and other writable directories.
* **Redirects** — Manage safe redirect rules stored in the database with import and export support.
* **Custom 404 Page** — Assign a WordPress page as the site 404 response while keeping the original URL and a real HTTP 404 status (no redirect).
* **Slug Manager** — Bulk-edit post and term slugs with conflict detection.
* **Health Report** — Generate a shareable site health snapshot covering environment, plugins, and common issues.
* **Reorder & Hide Sidebar** — Drag to reorder WordPress admin menu items, rename labels, nest items under another section, or hide items for all admins.
* **Users & Sessions** — Review administrators, role-less users, old accounts, and active sessions.
* **Roles & Capabilities** — Compare roles, apply capability templates, and audit dangerous caps.
* **Media Cleaner** — Review unattached media, missing attachment files, and unreferenced uploads.
* **Security Review** — Highlight common hardening and update issues.
* **Core File Integrity** — Verify WordPress core files against official checksums and flag unexpected changes.
* **Login Protection** — Custom login URL, brute-force limits, and related hardening controls.
* **Email Diagnostics** — Inspect wp_mail settings and send a test email.
* **Staging Mode** — Optional test-copy switches (all off by default): red STAGING label in the admin bar, ask search engines not to list this copy, hold outbound email so a staging shop does not message real customers, pause WP-Cron so queues do not run, and keep a short administrator-only mail log (source/type, CSV export) under the plugin uploads folder.
* **URL & HTTPS Doctor** — Explain whether the saved Home and Site addresses still match https, www, and how you opened the admin. Optional one-click loopback check of this site’s own home URL, and an optional count of leftover http:// copies of that address. Does not change the database.
* **Server & Runtime** — Read-only view of PHP limits, object-cache drop-in, other wp-content drop-ins, and must-use plugins that always load.
* **Content Audit** — Find hidden content issues such as empty titles, missing thumbnails, long slugs, and broken shortcodes.
* **Maintenance Mode** — Toggle a 503 maintenance page with a custom message and IP whitelist.
* **Plugin Sandbox** — Isolate plugin conflicts via a must-use loader: only your selected plugins load for your admin session.

= Translations =

* On **Tools › TSO Swiss Knife**, administrators can switch the plugin UI to Catalan (CAT), Spanish (ES), or English (ENG) without changing the site-wide language. This only affects that admin screen.
* Further locales can be contributed via [Translate WordPress](https://translate.wordpress.org/) once the plugin is published.

== External services ==

This plugin can optionally contact third-party services. None of these calls run unless a site administrator enables the related feature and, where required, provides an API key.

= Comment Antispam (optional) =

When **Comment Antispam** reputation or cloud checks are enabled, visitor data from comments or protected contact forms may be sent as follows:

* **Stop Forum Spam** (`https://api.stopforumspam.org/api`) — Used to look up whether an IP, email address, or username has been reported as spam. Sent on each checked submission (results may be cached briefly). Service: [Stop Forum Spam](https://www.stopforumspam.com/). [Terms of use](https://www.stopforumspam.com/legal) · [Privacy policy](https://www.stopforumspam.com/privacy).
* **AbuseIPDB** (`https://api.abuseipdb.com/api/v2/check`) — Used to check IP reputation. Sends the visitor IP and your AbuseIPDB API key (request header). Service: [AbuseIPDB](https://www.abuseipdb.com/). [Terms of use](https://www.abuseipdb.com/legal) · [Privacy policy](https://www.abuseipdb.com/privacy).
* **CleanTalk** (`https://moderate.cleantalk.org/api2.0`) — Used for cloud spam filtering when CleanTalk mode is selected. Sends your CleanTalk access key plus sender email, IP, nickname, URL, message content, and post/page context. Service: [CleanTalk](https://cleantalk.org/). [Terms of use and privacy policy](https://cleantalk.org/publicoffer).
* **Project Honey Pot (HTTP:BL)** — Optional DNS-based IP reputation lookup using your HTTP:BL access key and the visitor IPv4 address. Service: [Project Honey Pot](https://www.projecthoneypot.org/). [Terms of use](https://www.projecthoneypot.org/terms_of_use.php) · [Privacy policy](https://www.projecthoneypot.org/privacy_policy.php).
* **Akismet** — When cloud mode is set to Akismet and the Akismet plugin is active, spam checks are handled by Akismet according to its own settings and policies. Service: [Akismet](https://akismet.com/). [Terms of service](https://akismet.com/tos/) · [Privacy policy](https://automattic.com/privacy/).

= Core File Integrity (optional) =

When you run a core integrity scan, the plugin requests official WordPress core checksums from `https://api.wordpress.org/core/checksums/1.0/`. Only the WordPress version and locale are sent (no personal data). Service: [WordPress.org](https://wordpress.org/). [Privacy policy](https://wordpress.org/about/privacy/).

= URL & HTTPS Doctor (optional) =

When you click **Check this site**, the plugin requests this site’s own home URL through the WordPress HTTP API (a loopback, similar to Site Health). No third-party host is contacted and no personal data is sent. The request only runs after an administrator clicks the button.

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

= Does Staging Mode send customer emails from a test copy? =

Not if you enable **Do not send real emails**. WordPress still thinks the mail was accepted, but it never leaves the server. A short copy (recipient, subject, excerpt) is stored as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/logs/` for administrators. All Staging Mode switches are off until you turn them on. Turn them off before copying the database back to production.

= Does URL & HTTPS Doctor change my site address? =

No. It only explains mismatches (http vs https, www, folder, and constants locked in wp-config.php). The leftover-http count is also read-only. Use **Search & Replace** if you decide to rewrite stored URLs, after a backup — always run preview first.

= Does pausing scheduled tasks in Staging Mode delete cron events? =

No. Due events stay in **Cron Manager** but are not executed while that Staging Mode option is on. Turn it off on the live site so reminders and queues run again.

= Where does the plugin write files? =

Runtime config, the Staging Mode mail log, and other managed logs go under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/`. The Plugin Sandbox may install a must-use loader under `mu-plugins` (via the WordPress Filesystem API) so early plugin filtering can run; that loader is removed when no sandbox sessions remain. The plugin does not write `wp-content/debug.log` or edit `wp-config.php`.

= Does this plugin edit wp-config.php? =

No. Debug flags, security constants, and hidden-profile toggles are saved as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/` and applied at runtime. Constants already defined in `wp-config.php` always take precedence and cannot be overridden from the plugin.

= Does Debug Mode create or manage wp-content/debug.log? =

No. Debug Mode does not create, truncate, or rotate `wp-content/debug.log`. Enabling **Developer mode** only stores JSON flags (`WP_DEBUG`, `WP_DEBUG_LOG`, `SAVEQUERIES`, and related) in the plugin uploads config folder; WordPress or the server then writes `debug.log` as usual if logging is on. The Debug tab can list and preview common log paths when they already exist. Empty and shrink actions apply only to logs the plugin owns under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/` — never to `wp-content/debug.log`.

= Can Server Files write robots.txt or .htaccess? =

Yes, but only when you explicitly save from the **Server Files Review** module. It can write `robots.txt` and `.htaccess` at the site or WordPress root — not under `wp-content/uploads/`. Always review the generated content before saving on production.

= Who should use Search & Replace or the Options Editor? =

These tools are intended for experienced administrators and developers. Always run **Search & Replace** as a dry-run first and keep a database backup. In **Options Editor**, core options are protected, but deleting or editing third-party options can break plugins or themes. When in doubt, export a snapshot or test on staging.

= Does Comment Antispam send data to third parties? =

Only when you enable reputation or cloud checks and, where required, provide API keys. See the **External services** section above for each provider, what data is sent, and links to their terms and privacy policies. With all cloud features off, checks run locally (honeypot, rate limits, keyword rules, and similar).

= Why do I see two copies of this plugin after installing? =

That usually means the ZIP folder name was wrong (for example `…-main` from a GitHub download instead of `tso-swiss-knife-advanced-maintenance-developer-toolkit`). Remove the duplicate folder under `wp-content/plugins/`, keep only the folder whose name matches the plugin slug, and reactivate.

== Screenshots ==

1. Hidden WordPress Profiles — apply quick presets and toggle performance, content, and privacy constants via the plugin config under uploads (no wp-config.php editing).
2. Custom 404 Page — assign any WordPress page as the site 404 response while keeping the original URL and a real HTTP 404 status (no redirect).
3. Reorder & Hide Sidebar — drag to reorder WordPress admin menu items, rename labels, nest items under another section, or hide items for all admins.
4. Slow Query Monitor — inspect slow and live database queries when SAVEQUERIES is enabled, export the log, and open a summary from the admin bar.

== Changelog ==

= 1.0.6 =
* Staging Mode: optional admin-bar label, search-engine skip, outbound mail hold, and a short administrator mail log (all off by default).
* Staging Mode no longer filters blog_public (avoids saving “Discourage search engines” if you open Settings → Reading).
* Staging Mode: optional pause of WP-Cron (events stay scheduled but do not run), WooCommerce warning, test-host hint, mail log source/type, CSV export, and held-only filter.
* URL & HTTPS Doctor: explains home/site URL, https, and www mismatches without changing the database; loopback uses the real final URL after redirects.
* URL & HTTPS Doctor: optional count of leftover http:// copies of the public address, with a link that prefills Search & Replace (preview still required).
* Server & Runtime: read-only PHP limits, drop-ins, must-use plugins, object-cache status, and PHP vs MySQL clock skew.
* Server & Runtime: copy-to-clipboard summary and optional OPcache reset when the host allows it.
* Incomplete plugin uploads show an admin notice listing missing files instead of a blank tool tab.

= 1.0.5 =
* Cron Manager: core events are read-only in the UI and blocked from delete/reschedule in AJAX.
* Transients: purge expired/all also clears site transients.
* Roles: confirm before applying a capability template.
* Meta Editor and Slug Manager: ignore stale search AJAX responses.
* Debug Mode readme matches the Developer mode preset (no per-constant toggles).
* Regenerated CA/ES translations; removed leftover Recovery Mode strings.

= 1.0.4 =
* Prefill queue no longer cleared when loading the next 404 redirect form.
* Meta and Options editors: avoid double-serializing values; meta delete clears object cache.
* Redirects and Slug Manager: strip home subdirectory on source paths and loop detection.
* Content Audit: shortcode removal uses a tag boundary so [foo] does not match [foobar].
* Transients listing and purge: escape LIKE wildcards correctly.
* Slow Query Monitor: ignore admin-bar stacks for duplicate detection (like Query Monitor); keep last-page duplicate SQL at the top of the tab.

= 1.0.3 =
* Safer cron reschedule, meta edits by row ID, and search-replace primary-key handling.
* Redirects: subdirectory path matching, 404 alert counters, and prefill queue fixes.
* Options Editor protections, Content Audit empty-title query, uninstall cleanup, Requires at least 6.1.

= 1.0.2 =
* Health: site URL details on separate lines; autoload total includes yes/on/auto; accurate overdue cron count; security headers probe cached 15 minutes.
* Redirects: 404 log checkbox alignment; bulk prefill queue advances automatically after each save; 404 hit counts stay in sync under write throttling.
* Site Snapshot: environment diff panel clears after import.
* Content Audit: duplicate titles and shortcode inventory.
* Cron Manager: missed scheduled posts panel.

= 1.0.1 =
* Fixed bugs in modules.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.6 =
Adds Staging Mode (mail hold + test-copy label), URL & HTTPS Doctor, and a read-only Server & Runtime panel.

= 1.0.5 =
Cron Manager core-hook protection, site-transient purge, role template confirm, search race fixes, Debug Mode docs, and refreshed translations.

= 1.0.4 =
Fixes double-serialize in Meta/Options editors, redirect subdirectory sources, 404 prefill queue, shortcode removal, transients LIKE matching, and Slow Query duplicate snapshot.

= 1.0.3 =
Safer cron, meta, and search-replace; redirects and 404 alerts fixed; Options Editor and Content Audit hardened.

= 1.0.2 =
Health, redirects, snapshot, and translation fixes. Tested with WordPress 7.1.

= 1.0.1 =
Fixed bugs in modules.

= 1.0.0 =
Initial release.
