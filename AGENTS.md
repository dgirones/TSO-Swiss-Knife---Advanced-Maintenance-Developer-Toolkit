# TSO Swiss Knife — agent notes

Plugin slug / text domain: `tso-swiss-knife-advanced-maintenance-developer-toolkit`  
PHP prefix: `tsosk_` / `TSOSK_` (never short `tso_` / `TSO_`)  
User-facing languages: English source, Catalan (`ca`) and Spanish (`es_ES`) catalogs in `languages/`.

## Translations (required before done)

**Every change that adds, edits, or removes gettext strings in PHP or JS must keep CA and ES complete.** Do not mark the task complete until `bash scripts/i18n-check.sh` exits 0.

That check fails on missing POT entries, empty `msgstr` / `msgstr[n]`, fuzzy rows, missing `.mo` files, or printf placeholder mismatches.

When strings change:

1. Regenerate the POT from the plugin root (use the WordPress.org slug, not the workspace folder name):

   ```bash
   wp i18n make-pot . languages/tso-swiss-knife-advanced-maintenance-developer-toolkit.pot \
     --domain=tso-swiss-knife-advanced-maintenance-developer-toolkit \
     --slug=tso-swiss-knife-advanced-maintenance-developer-toolkit
   ```

2. Merge into both PO files:

   ```bash
   wp i18n update-po languages/tso-swiss-knife-advanced-maintenance-developer-toolkit.pot \
     languages/tso-swiss-knife-advanced-maintenance-developer-toolkit-ca.po
   wp i18n update-po languages/tso-swiss-knife-advanced-maintenance-developer-toolkit.pot \
     languages/tso-swiss-knife-advanced-maintenance-developer-toolkit-es_ES.po
   ```

3. Fill every new or empty Catalan and Spanish string (no leftover English UI, no fuzzy). Keep `%s` / `%d` / `%1$s` placeholders identical to the source.

4. Compile catalogs and re-check:

   ```bash
   python3 languages/compile-mo.py
   bash scripts/i18n-check.sh
   ```

`compile-mo.py` writes `.mo` files, Jed JSON for script translations, then runs the same completeness check. Gutenberg / `wp.i18n` strings also need `wp_set_script_translations()` (already wired for `tsosk-block-404-url`).

JS strings passed through PHP `__()` in `wp_localize_script()` are covered by the PO/MO flow above.

## Cursor Cloud specific instructions

- Plugin root is the repository root (`tso-swiss-knife.php`).
- After string changes, run `bash scripts/i18n-check.sh` in this environment before finishing (WP-CLI `wp i18n` is available; `polib` is required for `compile-mo.py`).
- Local WordPress for `load_textdomain()` smoke tests lives at `/home/ubuntu/wp` when that tree is present.
