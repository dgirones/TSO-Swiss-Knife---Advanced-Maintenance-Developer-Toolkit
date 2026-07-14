#!/usr/bin/env python3
"""Compile bundled TSOSK .po files to .mo in languages/."""

from __future__ import annotations

import sys
from pathlib import Path

try:
	import polib
except ImportError as exc:
	print("polib is required: pip install polib", file=sys.stderr)
	raise SystemExit(1) from exc

DOMAIN = "tso-swiss-knife-advanced-maintenance-developer-toolkit"
LOCALES = ("ca", "es_ES")


def main() -> int:
	root = Path(__file__).resolve().parent.parent
	lang_dir = root / "languages"
	ok = True

	for locale in LOCALES:
		po_path = lang_dir / f"{DOMAIN}-{locale}.po"
		mo_path = lang_dir / f"{DOMAIN}-{locale}.mo"

		if not po_path.is_file():
			print(f"skip (missing): {po_path.name}", file=sys.stderr)
			ok = False
			continue

		po = polib.pofile(str(po_path))
		po.save_as_mofile(str(mo_path))
		print(f"compiled: {mo_path.name}")

	return 0 if ok else 1


if __name__ == "__main__":
	raise SystemExit(main())
