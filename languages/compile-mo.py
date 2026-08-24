#!/usr/bin/env python3
"""
Compile tso-swiss-knife-advanced-maintenance-developer-toolkit-*.po to .mo
and verify CA/ES catalogs against the POT.

Requires: pip install polib
Run from plugin root: python3 languages/compile-mo.py
"""
from pathlib import Path
import shutil
import subprocess
import sys

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

wp = shutil.which("wp")
if wp:
	json_run = subprocess.run(
		[wp, "i18n", "make-json", str(here), "--no-purge"],
		check=False,
		capture_output=True,
		text=True,
	)
	print(json_run.stdout.strip() or json_run.stderr.strip())
	if json_run.returncode != 0:
		raise SystemExit(json_run.returncode)
else:
	print("SKIP wp i18n make-json (wp not in PATH)")

check = subprocess.run(
	[sys.executable, str(here / "i18n-check.py")],
	check=False,
)
raise SystemExit(check.returncode)
