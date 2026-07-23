=== TSO Swiss Knife – Advanced Maintenance & Developer Toolkit ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: maintenance, developer tools, cron, debug, database
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin toolkit with 35+ modules for cron, debug, security, database, redirects, roles, maintenance, and site health reports.

== Description ==

TSO Swiss Knife gives WordPress developers and site administrators a single, well-organised panel (under **Tools › TSO Swiss Knife**) to inspect and control the internal systems that affect performance, stability, and security.

= Included modules =

* **Activity History** — Central log of changes across all plugin tools (options edited, database replacements, maintenance mode, admin menu, and more). Pinned as the default favorite for quick access.
* **Hidden WordPress Profiles** — Switch between curated admin UI profiles that show or hide module groups for cleaner workflows.
* **Cron Manager** — List, manually run, or delete scheduled WP-Cron events. Core hooks are protected from accidental deletion.
* **Action Scheduler** — Inspect WooCommerce Action Scheduler tables, pending actions, and queue health when the library is present.
* **Debug Mode** — Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, and SAVEQUERIES via JSON flags stored under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/` — no wp-config.php editing required.
* **Options Editor** — Search, inspect, edit, and safely delete `wp_options` rows with core options protected.
* **Meta Editor** — Browse and edit post, user, term, and comment meta with type-aware validation.
* **Option Library** — Save named option presets and re-apply them across environments.
* **Export/Import TSO Configuration** — Back up and restore plugin settings and module preferences as JSON.
* **TSO Link Inspector** — In-plugin promo for the free [TSO Link Inspector](https://wordpress.org/plugins/tso-link-inspector/) companion plugin (broken-link scanner and fixer on WordPress.org).
* **Transients** — Filter by status and purge expired or all transients in bulk.
* **WP Constants** — Read-only overview of relevant constants grouped by category.
* **WP Internals** — Inspect post types, taxonomies, roles, query vars, rewrite tags, and shortcodes.
* **REST API Controls** — Disable anonymous REST API access or block individual namespaces.
* **Heartbeat Controls** — Set Heartbeat mode (default / disable frontend / disable editor / disable all) and interval.
* **Update Manager** — Review pending core, plugin, and theme updates, optionally block update checks (staging), and control update email notifications.
* **TSO Options & Tables Cleaner** — In-plugin promo for the free [TSO Options & Tables Cleaner](https://wordpress.org/plugins/tso-options-tables-cleaner/) companion plugin (database cleanup, orphan options, backups, and table optimization on WordPress.org).
* **Slow Query Monitor** — Surface slow database queries logged when SAVEQUERIES is enabled.
* **Search & Replace** — Run dry-run or live serialized-safe search and replace across database tables.
* **Hooks Inspector** — Browse the live `$wp_filter` global, with callback details and a real-time search filter.
* **Rewrite Rules Flush** — Soft or hard flush with a single click; search within the current rules table.
* **Server Files Review** — Scan for unexpected PHP files in uploads and other writable directories.
* **Redirects** — Manage safe redirect rules stored in the database with import and export support.
* **Custom 404 Page** — Assign a WordPress page as the site 404 template with live preview before saving.
* **Slug Manager** — Bulk-edit post and term slugs with conflict detection.
* **Health Report** — Generate a shareable site health snapshot covering environment, plugins, and common issues.
* **Reorder & Hide Sidebar** — Choose which admin sidebar items to show, drag to reorder, rename labels, and move submenus under another section. Simpler than dedicated menu editor plugins.
* **Users & Sessions** — Review administrators, role-less users, old accounts, and active sessions.
* **Roles & Capabilities** — Compare roles, apply capability templates, and audit dangerous caps.
* **Media Cleaner** — Review unattached media, missing attachment files, and unreferenced uploads.
* **Security Review** — Highlight common hardening and update issues.
* **Core File Integrity** — Verify WordPress core files against official checksums and flag unexpected changes.
* **Login Protection** — Custom login URL, brute-force limits, and related hardening controls.
* **Email Diagnostics** — Inspect wp_mail settings and send a test email.
* **Plugin Footprint** — Estimate each plugin's options, cron hooks, shortcodes, and REST routes.
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

== Installation ==

1. Upload the `tso-swiss-knife-advanced-maintenance-developer-toolkit` folder to `/wp-content/plugins/`, or use **Plugins › Add New › Upload Plugin** with the ZIP.
2. Activate the plugin via **Plugins › Installed Plugins**.
3. Navigate to **Tools › TSO Swiss Knife**.

== Frequently Asked Questions ==

= Does this plugin work with object-cache plugins like Redis? =

Yes. The Object Cache Tools module detects the active driver and calls `wp_cache_flush()`, which delegates correctly to any persistent cache backend.

= Is it safe to delete an option from the Options Editor tab? =

The module protects a list of known WordPress core options. For third-party options, verify in your code or database that they are truly unused before deleting.

= Does enabling Maintenance Mode block the admin? =

No. Logged-in administrators are always bypassed, regardless of IP whitelist settings.

= Can I run multiple plugin-testing tools at once? =

Use only the **Plugin Sandbox** in this plugin. Combining it with other per-user plugin override tools may produce unpredictable results.

= Does Update Manager change WordPress auto-updates? =

No. Automatic updates are managed only by WordPress core (**Dashboard → Updates**). Update Manager can block update checks on staging sites, hide specific plugin updates, and control update email notifications — it does not write `auto_update_*` site options or hook `auto_update_*` filters.

= Where does the plugin write files? =

Runtime config and managed logs go under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/`. The Plugin Sandbox may install a must-use loader under `mu-plugins` (via the WordPress Filesystem API) so early plugin filtering can run; that loader is removed when no sandbox sessions remain. The plugin does not write `wp-content/debug.log` or edit `wp-config.php`.

= Does this plugin edit wp-config.php? =

No. Debug flags, security constants, and hidden-profile toggles are saved as JSON under `wp-content/uploads/tso-swiss-knife-advanced-maintenance-developer-toolkit/config/` and applied at runtime. Constants already defined in `wp-config.php` always take precedence and cannot be overridden from the plugin.

= Does Debug Mode create or manage wp-content/debug.log? =

Debug Mode only stores JSON flags (for example `WP_DEBUG`, `WP_DEBUG_LOG`, `SAVEQUERIES`) in the plugin uploads config folder. It does not create, truncate, or rotate `wp-content/debug.log`. If logging is enabled, WordPress or your server writes that file as usual. The Debug tab can list and preview common log paths when they already exist.

= Can Server Files write robots.txt or .htaccess? =

Yes, but only when you explicitly save from the **Server Files Review** module. It can write `robots.txt` and `.htaccess` at the site or WordPress root — not under `wp-content/uploads/`. Always review the generated content before saving on production.

= Who should use Search & Replace or the Options Editor? =

These tools are intended for experienced administrators and developers. Always run **Search & Replace** as a dry-run first and keep a database backup. In **Options Editor**, core options are protected, but deleting or editing third-party options can break plugins or themes. When in doubt, export a snapshot or test on staging.

= Does Comment Antispam send data to third parties? =

Only when you enable reputation or cloud checks and, where required, provide API keys. See the **External services** section above for each provider, what data is sent, and links to their terms and privacy policies. With all cloud features off, checks run locally (honeypot, rate limits, keyword rules, and similar).

= Why do I see two copies of this plugin after installing? =

That usually means the ZIP folder name was wrong (for example `…-main` from a GitHub download instead of `tso-swiss-knife-advanced-maintenance-developer-toolkit`). Remove the duplicate folder under `wp-content/plugins/`, keep only the folder whose name matches the plugin slug, and reactivate.

== Screenshots ==

1. Cron Manager — list of scheduled events with manual-run and delete actions.
2. Debug Mode — toggle debug constants via uploads config without editing wp-config.php.
3. Transients Manager — filter by status and purge expired entries in bulk.
4. TSO Options & Tables Cleaner — companion plugin promo for database cleanup and optimization.
5. Maintenance Mode — toggle with custom message and IP whitelist.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
