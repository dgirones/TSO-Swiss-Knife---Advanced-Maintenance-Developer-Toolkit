#!/usr/bin/env python3
"""
Compile tso-swiss-knife-advanced-maintenance-developer-toolkit-*.po to .mo.

Requires: pip install polib
Run from plugin root: python languages/compile-mo.py
"""
from pathlib import Path

try:
	import polib
except ImportError:
	raise SystemExit("Install polib first: python -m pip install polib") from None

here = Path(__file__).resolve().parent
slug = "tso-swiss-knife-advanced-maintenance-developer-toolkit"
for po in sorted(here.glob(f"{slug}-*.po")):
	mo = po.with_suffix(".mo")
	polib.pofile(str(po)).save_as_mofile(str(mo))
	print(f"OK {mo.name} ({mo.stat().st_size} bytes)")
