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
* **Debug Mode** — Toggle WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, and SAVEQUERIES via auto-generated flags in `wp-content/uploads/tsosk-config/` — no wp-config.php editing required.
* **Options Editor** — Search, inspect, edit, and safely delete `wp_options` rows with core options protected.
* **Meta Editor** — Browse and edit post, user, term, and comment meta with type-aware validation.
* **Option Library** — Save named option presets and re-apply them across environments.
* **Export/Import TSO Configuration** — Back up and restore plugin settings and module preferences as JSON.
* **TSO Link Inspector** — In-plugin promo for the free [TSO Link Inspector](https://wordpress.org/plugins/tso-link-inspector/) companion plugin (broken-link scanner and fixer on WordPress.org).
* **Transients** — Filter by status and purge expired or all transients in bulk.
* **WP Constants** — Read-only overview of relevant constants grouped by category.
* **WP Internals** — Inspect post types, taxonomies, roles, query vars, rewrite tags, image sizes, and shortcodes.
* **REST API Controls** — Disable anonymous REST API access or block individual namespaces.
* **Heartbeat Controls** — Set Heartbeat mode (default / disable frontend / disable editor / disable all) and interval.
* **Update Manager** — Review pending core, plugin, and theme updates, optionally block update checks (staging), and control update email notifications.
* **TSO Options & Tables Cleaner** — In-plugin promo for the free [TSO Options & Tables Cleaner](https://wordpress.org/plugins/tso-options-tables-cleaner/) companion plugin (database cleanup, orphan options, backups, and table optimization on WordPress.org).
* **Slow Query Monitor** — Surface slow database queries logged when SAVEQUERIES is enabled.
* **Search & Replace** — Run dry-run or live serialized-safe search and replace across database tables.
* **Hooks Inspector** — Browse the live `$wp_filter` global, with callback details and a real-time search filter.
* **Rewrite Rules Flush** — Soft or hard flush with a single click; search within the current rules table.
* **Object Cache Tools** — View the active cache driver and flush it instantly.
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

* All user-facing strings use the `tso-swiss-knife-advanced-maintenance-developer-toolkit` text domain and standard WordPress gettext functions (`__()`, `_e()`, `esc_html__()`, etc.).
* Bundled translations ship in `/languages` as `.po` and `.mo` files (Catalan and Spanish), plus the template `languages/tso-swiss-knife-advanced-maintenance-developer-toolkit.pot`. After editing `.po` files, compile `.mo` with `py scripts/tsosk-compile-mo.py`. If a bundled `.mo` is missing and the `.po` is newer, the plugin may recompile a cached copy under `wp-content/uploads/tsosk-l10n/`.
* On **Tools › TSO Swiss Knife**, administrators can switch the plugin UI to Catalan (CAT), Spanish (ES), or English (ENG) without changing the site-wide language. This only affects that admin screen.
* Further locales can be contributed via [Translate WordPress](https://translate.wordpress.org/) once the plugin is published.

= Design principles =

* Strictly follows WordPress Coding Standards.
* All outputs escaped; all inputs sanitised and nonce-verified.
* No options autoloaded unnecessarily.
* Writable config flags stored under `wp-content/uploads/tsosk-config/` (WordPress.org compliant).
* No external HTTP requests on the front-end.

== Installation ==

1. Upload the `tso-swiss-knife-advanced-maintenance-developer-toolkit` folder to `/wp-content/plugins/`.
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
