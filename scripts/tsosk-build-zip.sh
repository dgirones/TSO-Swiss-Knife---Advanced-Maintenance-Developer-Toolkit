#!/usr/bin/env bash
# Build a WordPress-ready plugin ZIP with the canonical lowercase folder slug.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="tso-swiss-knife-advanced-maintenance-developer-toolkit"
DIST="${ROOT}/dist"
STAGE="${DIST}/${SLUG}"
ZIP="${DIST}/${SLUG}.zip"

rm -rf "${DIST}"
mkdir -p "${STAGE}"

copy_item() {
	local src="${ROOT}/$1"
	local dest="${STAGE}/$1"
	if [[ -e "${src}" ]]; then
		mkdir -p "$(dirname "${dest}")"
		cp -a "${src}" "${dest}"
	fi
}

for item in \
	assets \
	includes \
	languages \
	mu-plugin \
	scripts \
	tso-swiss-knife.php \
	uninstall.php \
	readme.txt \
	LICENSE
do
	copy_item "${item}"
done

(
	cd "${DIST}"
	rm -f "${SLUG}.zip"
	zip -rq "${SLUG}.zip" "${SLUG}"
)

echo "Created ${ZIP}"
echo "Folder inside ZIP: ${SLUG}/"
