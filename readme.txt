=== TSO Swiss Knife – Advanced Maintenance & Developer Toolkit ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: maintenance, developer tools, cron, debug, database
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.6
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
* **Users & Sessions** — Summary cards (totals and users per role), per-account session cards with single or full session close, a searchable and sortable All users list with bulk role change (including "select all matching users" and Undo), inactive accounts, and login history.
* **Roles & Capabilities** — Step-by-step screen: choose a role, review what each capability allows, add or remove capabilities, apply templates, clone a role or compare two roles. Dangerous capabilities are blocked or flagged.
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

= 1.2.6 =
* Login Protection: new optional counter per username (stops attackers who change IP on every attempt), IPv6 counted per /64 network, lockout emails limited to 3 every 15 minutes, and fixes for the attempts list growing without limit, "Clear Log" lifting active lockouts, invalid IP lines, self-lockout with login maintenance, timezone and the Active/Inactive badge.
* Security Review: one alert when a new administrator is created (it sent two), FORCE_SSL_ADMIN refused when you are not on HTTPS, wp-config.php-locked toggles no longer saved, and a more truthful uploads PHP check (commented rules, Nginx/IIS).
* Comment Anti-Spam: WPForms trap fields now inside the form, clearer Contact Form 7 block messages, Elementor Pro blocks logged and Learning mode respected, IPv6 per /64 in rate limits and bans, and the IP whitelist validated on save.
* Search & Replace: post GUIDs are skipped by default (new "Include post GUIDs" option) and the history tables of other TSO plugins are no longer searched or rewritten.
* Server Files: the "Add security headers" button always shows, a virtual robots.txt is detected, informational notices are gone, and "No obvious issues were detected" only appears when it is true.
* Redirects: the 404 Monitor has a "Select all" button and no longer logs or offers a redirect for the home page.
* Also: Leftovers ignores WordPress's "recently_edited" option, Content Audit shows where each shortcode is used, and Media Footprint shows and links each large file's folder.

= 1.2.5 =
* Fix in Slow Query Monitor: duplicated queries now show which plugin, theme or WordPress core runs them even when Origin detection is off or the query ran before the monitor started (the origin and the potential troublemaker are read from the query's caller chain), and the admin bar's Duplicated link now jumps straight to the duplicates table.
* Improved: Slow Query Monitor is now organised in three tabs (Settings, Slow queries, Database queries), like Redirects; links from the admin bar open the right tab, it always opens on Settings, saving the settings keeps you on the same tab, and the panel texts now match what each tab shows.
* Fix in Search & Replace: when the only match is stored as PHP objects or serialized data (which cannot be edited safely) the preview no longer says "no matches"; it explains that nothing can be changed and names the table or option left untouched.
* Fix in URL Doctor: the leftover http count no longer includes update-checker caches of other plugins (external_updates-*), which Search & Replace cannot change and are not site content.
* Fix in Redirects: saving or importing a redirect no longer drops percent-encoded characters (such as %20) from the source or the target; sources written with %20 are stored as the real path (a space) so they match the visited address.
* Improved: the "Show the failed URL on the page" help in Custom 404 Page now shows numbered steps and one card per shortcode, with a Copy button and a preview of what visitors will see.
* Improved: Maintenance Mode now lets you set the logo width, the colours of the page, a subtitle, a contact link and extra CSS, announce the expected return (with an optional live countdown and a matching Retry-After header), and switch on and off automatically with a schedule. Fixed stray spaces saved around the message and the IP list, the Export/Import configuration now keeps every Maintenance setting, the Site Status badge follows the schedule, and the page is marked noindex.

= 1.2.4 =
* Added: Plugin & Theme Leftovers — finds database rows that point to plugins or themes that are no longer installed (options, meta, post content), compares them with what is installed and lets you choose what to delete, with a backup to undo.
* Fixes to Search & Replace: one single confirmation before executing, the result or error stays on screen, and a failed run (time limit, fatal error) now reports its reason.
* Fixes to Update Manager: language packs reported as failed although WordPress installed them; the install message in the Overview lost its layout after the first click.
* Fix in URL & HTTPS Doctor: the leftover http counter no longer counts this plugin's own options (the Search & Replace backup and the activity log keep the old text on purpose), so it reaches 0 after a replace. The count now also covers all post statuses and URLs stored with escaped slashes (the same rows Search & Replace changes), and says that Search & Replace may preview more rows because it looks in every table. The address check now recognises sites behind a proxy or CDN that ends https (with the wp-config.php fix to apply), the table is easier to read, and the server check shows when it was run.
* Fixes to Activity History, Overview and Export/Import: entries from Plugin & Theme Leftovers now show their row count correctly and are translated, a value of "0" is no longer shown as empty, the list never exceeds the configured limit, the Overview recommendations reflect the plugins installed right now, "Undo last import" appears right after an import, and restoring previous settings keeps each option's autoload flag, and the Overview's Recent activity card no longer stretches on mobile with long entries.
* Changed: Export/Import TSO Configuration now sits in Profiles & config (4th place), and TSO Link Inspector and TSO Options & Tables Cleaner are merged into a single "More TSO Plugins" tab, the last one of Database. Saved sidebar layouts and old links are migrated automatically.

