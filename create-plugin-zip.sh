#!/usr/bin/env bash
#
# Build kennelflow-core.zip for WordPress.org / manual install (run from this directory):
#   ./create-plugin-zip.sh
#
# Uses rsync staging so root paths (.git/, node_modules/, etc.) cannot slip into the archive.
# (Plain `zip -x '*/node_modules/*'` does NOT exclude top-level node_modules/*.)
#
set -euo pipefail

PLUGIN_SLUG="kennelflow-core"
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT_ZIP="$ROOT/${PLUGIN_SLUG}.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

DESTDIR="$TMP/${PLUGIN_SLUG}"
mkdir -p "$DESTDIR"

# rsync: trailing slash on source copies contents into DESTDIR.
rsync -a "$ROOT/" "$DESTDIR/" \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.cursor/' \
	--exclude='/.wordpress-org/' \
	--exclude='.cursorrules' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.editorconfig' \
	--exclude='.vscode/' \
	--exclude='.idea/' \
	--exclude='.travis.yml' \
	--exclude='.gitlab-ci.yml' \
	--exclude='.deploy-trigger' \
	--exclude='.deploy-test' \
	--exclude='.DS_Store' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='._*' \
	--exclude='node_modules/' \
	--exclude='/vendor/' \
	--exclude='/tests/' \
	--exclude='/test-results/' \
	--exclude='/extras/' \
	--exclude='/tools/' \
	--exclude='/src/' \
	--exclude='/phpunit.xml' \
	--exclude='/phpunit.xml.dist' \
	--exclude='/phpcs.xml' \
	--exclude='/phpcs.xml.dist' \
	--exclude='/phpcs-security.xml' \
	--exclude='/playwright.config.js' \
	--exclude='/webpack.config.js' \
	--exclude='*.zip' \
	--exclude='*.sh' \
	--exclude='*.md'

rm -f "$OUT_ZIP"
( cd "$TMP" && zip -r "$OUT_ZIP" "${PLUGIN_SLUG}" )

echo "Created ${OUT_ZIP}"
