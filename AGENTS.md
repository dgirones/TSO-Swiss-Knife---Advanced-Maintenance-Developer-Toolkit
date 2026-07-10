# AGENTS.md

## Cursor Cloud specific instructions

This repository is a single **WordPress plugin** ("TSO Swiss Knife", text domain `tso-swiss-knife`). It has no build step, no Composer/npm dependencies, and no automated test suite — it is a plain drop-in plugin that only runs inside a WordPress site (requires PHP 8.0+, WordPress 5.9+, MySQL/MariaDB). See `readme.txt` for the module list and install steps.

### Dev stack layout (provisioned in the VM snapshot)

- PHP 8.3 CLI, MariaDB server, and WP-CLI (`wp`) are installed system-wide.
- A WordPress core install lives at `/home/ubuntu/wp`, with DB `wordpress` (user `wpuser` / pass `wppass`, host `127.0.0.1`).
- This repo (`/workspace`) is symlinked into WordPress as the active plugin: `/home/ubuntu/wp/wp-content/plugins/tso-swiss-knife -> /workspace`. Editing files in `/workspace` is immediately live (no build/copy needed).
- Admin login: user `admin` / pass `admin123`. Plugin UI: `http://localhost:8080/wp-admin/tools.php?page=tso-swiss-knife`.

### Starting the services (NOT done by the update script)

MariaDB and the PHP web server do not auto-start on a fresh pod. Start them before testing:

```bash
# 1. Start MariaDB (data persists at /var/lib/mysql)
sudo mkdir -p /var/run/mysqld && sudo chown mysql:mysql /var/run/mysqld
sudo mariadbd-safe >/tmp/mariadb.log 2>&1 &
sleep 8 && sudo mysqladmin ping   # expect "mysqld is alive"

# 2. Start the WordPress dev server (use a tmux session so it persists)
cd /home/ubuntu/wp && php -S 0.0.0.0:8080 -t /home/ubuntu/wp
```

If `/home/ubuntu/wp` does not exist (e.g. a pod without the snapshot), recreate it with WP-CLI:
`wp core download`, `wp config create --dbname=wordpress --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1`, `wp core install --url=http://localhost:8080 --title="TSO Dev" --admin_user=admin --admin_password=admin123 --admin_email=admin@example.com --skip-email`, then re-create the plugin symlink.

### Lint / test / run

- **Lint (syntax):** there is no PHPCS config in the repo; the reliable check is `php -l` on every PHP file. From `/workspace`:
  `find . -name '*.php' -not -path './.git/*' -exec php -l {} \;` (all must report "No syntax errors detected").
- **Run:** start the two services above and open the plugin admin page.
- **Test:** manual, in-browser under **Tools › TSO Swiss Knife**. Use WP-CLI to inspect persisted state, e.g. `cd /home/ubuntu/wp && wp option list --search='tsosk*'`.

### Gotchas

- Writable plugin config flags (debug/security profiles) are written to `wp-content/uploads/tsosk-config/`, not `wp-config.php`.
- The "Reorder & Hide Sidebar" module stores its customizations in the `tsosk_admin_menu_settings` and `tsosk_admin_menu_manifest` options (serialized arrays). Saving is AJAX-based with no traditional admin notice; confirm success via the persisted option value or the `tsosk_activity_log` option rather than looking only for a banner.
- Requires PHP 8.0+; the plugin `exit`s immediately if loaded outside WordPress (`ABSPATH` guard), so run it through WordPress, not standalone.
