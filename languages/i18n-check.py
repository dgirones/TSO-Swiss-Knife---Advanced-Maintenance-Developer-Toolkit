#!/usr/bin/env python3
"""
Verify CA and ES catalogs are complete against the POT.

Exit 0 only when:
  - every POT msgid exists in both PO files
  - no empty msgstr / msgstr[n]
  - no fuzzy entries
  - printf placeholders match between source and translation

Run from plugin root: python3 languages/i18n-check.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SLUG = "tso-swiss-knife-advanced-maintenance-developer-toolkit"
DOMAIN = SLUG
FMT = re.compile(
	r"%(?:\d+\$)?[-+#0 ]*\d*(?:\.\d+)?[sdDuoxXfFeEgGcCbBhH%]|%%"
)


def parse_po(path: Path) -> list[dict]:
	content = path.read_text(encoding="utf-8")
	chunks = re.split(r"\n\s*\n", content)
	entries: list[dict] = []
	for chunk in chunks:
		if "msgid" not in chunk:
			continue
		fuzzy = bool(re.search(r"^#, fuzzy\b", chunk, re.M))
		msgid = _grab(chunk, "msgid")
		if msgid is None:
			continue
		plural = _grab(chunk, "msgid_plural")
		msgstrs: dict[int, str] = {}
		for mm in re.finditer(r'^msgstr(?:\[(\d+)\])? "(.*)"', chunk, re.M):
			idx = int(mm.group(1)) if mm.group(1) is not None else 0
			val = mm.group(2)
			after = chunk[mm.end() :]
			for line in after.splitlines():
				cm = re.match(r'^"(.*)"\s*$', line)
				if cm:
					val += cm.group(1)
				else:
					break
			msgstrs[idx] = _unescape(val)
		entries.append(
			{
				"msgid": _unescape(msgid),
				"plural": _unescape(plural) if plural is not None else None,
				"msgstr": msgstrs,
				"fuzzy": fuzzy,
			}
		)
	return entries


def _grab(chunk: str, kind: str) -> str | None:
	m = re.search(rf'^{kind} "(.*)"\s*$', chunk, re.M)
	if not m:
		m2 = re.search(rf'^{kind} ""\n((?:"[^"]*"\n)+)', chunk, re.M)
		if not m2:
			return None
		return "".join(re.findall(r'"([^"]*)"', m2.group(1)))
	s = m.group(1)
	rest = chunk[m.end() :]
	for line in rest.splitlines():
		mm = re.match(r'^"(.*)"\s*$', line)
		if mm:
			s += mm.group(1)
		else:
			break
	return s


def _unescape(s: str) -> str:
	return (
		s.replace(r"\\", "\x00")
		.replace(r"\n", "\n")
		.replace(r"\t", "\t")
		.replace(r"\"", '"')
		.replace("\x00", "\\")
	)


def _fmts(s: str) -> tuple[str, ...]:
	return tuple(sorted(FMT.findall(s or "")))


def check_locale(pot_entries: list[dict], po_path: Path, nplurals: int) -> list[str]:
	errors: list[str] = []
	entries = parse_po(po_path)
	by_id = {(e["msgid"], e["plural"]): e for e in entries if e["msgid"] != ""}
	for src in pot_entries:
		if src["msgid"] == "":
			continue
		key = (src["msgid"], src["plural"])
		if key not in by_id:
			errors.append(f"missing msgid: {src['msgid'][:80]!r}")
			continue
		dst = by_id[key]
		if dst["fuzzy"]:
			errors.append(f"fuzzy: {src['msgid'][:80]!r}")
		if src["plural"] is not None:
			for i in range(nplurals):
				val = dst["msgstr"].get(i, "")
				if not val.strip():
					errors.append(f"empty msgstr[{i}]: {src['msgid'][:80]!r}")
				else:
					expect = src["msgid"] if i == 0 else src["plural"]
					if _fmts(expect) != _fmts(val):
						errors.append(
							f"format msgstr[{i}]: {src['msgid'][:60]!r} {_fmts(expect)} vs {_fmts(val)}"
						)
		else:
			val = dst["msgstr"].get(0, "")
			if not val.strip():
				errors.append(f"empty msgstr: {src['msgid'][:80]!r}")
			elif _fmts(src["msgid"]) != _fmts(val):
				errors.append(
					f"format: {src['msgid'][:60]!r} {_fmts(src['msgid'])} vs {_fmts(val)}"
				)
	return errors


def main() -> int:
	pot_path = HERE / f"{SLUG}.pot"
	if not pot_path.is_file():
		print(f"FAIL missing {pot_path.name}", file=sys.stderr)
		return 1
	pot = parse_po(pot_path)
	pot_count = sum(1 for e in pot if e["msgid"] != "")
	failed = False
	for loc, nplurals in (("ca", 2), ("es_ES", 2)):
		po_path = HERE / f"{SLUG}-{loc}.po"
		mo_path = HERE / f"{SLUG}-{loc}.mo"
		if not po_path.is_file():
			print(f"FAIL {loc}: missing {po_path.name}", file=sys.stderr)
			failed = True
			continue
		errors = check_locale(pot, po_path, nplurals)
		if errors:
			failed = True
			print(f"FAIL {loc}: {len(errors)} issue(s)", file=sys.stderr)
			for err in errors[:40]:
				print(f"  {err}", file=sys.stderr)
			if len(errors) > 40:
				print(f"  … {len(errors) - 40} more", file=sys.stderr)
		else:
			po_count = sum(1 for e in parse_po(po_path) if e["msgid"] != "")
			mo_note = "mo ok" if mo_path.is_file() else "WARNING missing .mo"
			print(f"OK {loc}: {po_count} strings (POT {pot_count}) {mo_note}")
			if not mo_path.is_file():
				failed = True
	if failed:
		return 1
	print(f"OK domain {DOMAIN}: CA and ES complete, 0 empty, 0 fuzzy")
	return 0


if __name__ == "__main__":
	sys.exit(main())
