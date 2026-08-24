#!/usr/bin/env bash
# Verify bundled CA/ES gettext catalogs are complete (0 empty, 0 fuzzy).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "$ROOT/languages/i18n-check.py"
