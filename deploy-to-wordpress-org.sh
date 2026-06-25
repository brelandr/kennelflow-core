#!/usr/bin/env bash
#
# Deploy KennelFlow Core to WordPress.org SVN (trunk, tags/VERSION, assets/).
#
# Usage (from this directory):
#   VERSION=0.2.2 ./deploy-to-wordpress-org.sh
#   VERSION=0.2.2 ./deploy-to-wordpress-org.sh --commit
#
set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="kennelflow-core"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_DIR="$(dirname "$PLUGIN_ROOT")/${SLUG}-svn"
VERSION="${VERSION:-$(grep -E "^ \* Version:" "$PLUGIN_ROOT/kennelflow-core.php" | sed 's/[^0-9.]//g')}"
ASSETS_SRC="${PLUGIN_ROOT}/.wordpress-org"
DO_COMMIT=false

if [[ "${1:-}" == "--commit" ]]; then
	DO_COMMIT=true
fi

if [[ -z "$VERSION" ]]; then
	echo "Could not detect VERSION from kennelflow-core.php" >&2
	exit 1
fi

echo "Plugin root: ${PLUGIN_ROOT}"
echo "SVN URL:     ${SVN_URL}"
echo "SVN dir:     ${SVN_DIR}"
echo "Version:     ${VERSION}"
echo ""

if [[ -d "$SVN_DIR/.svn" ]]; then
	echo "Updating existing SVN checkout..."
	svn update "$SVN_DIR"
else
	echo "Checking out SVN repository..."
	rm -rf "$SVN_DIR"
	svn checkout "$SVN_URL" "$SVN_DIR"
fi

echo "Syncing plugin files to trunk..."
rsync -a --delete \
	--exclude='.cursorrules' \
	--exclude='.cursor/' \
	--exclude='.git/' \
	--exclude='.github/' \
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
	--exclude='/scripts/' \
	--exclude='/phpunit.xml' \
	--exclude='/phpunit.xml.dist' \
	--exclude='/phpcs.xml' \
	--exclude='/phpcs.xml.dist' \
	--exclude='/phpcs-security.xml' \
	--exclude='/playwright.config.js' \
	--exclude='/webpack.config.js' \
	--exclude='/.wordpress-org/' \
	--exclude='*.zip' \
	--exclude='*.sh' \
	--exclude='*.md' \
	"${PLUGIN_ROOT}/" "${SVN_DIR}/trunk/"

echo "Creating tag tags/${VERSION}..."
mkdir -p "${SVN_DIR}/tags"
rm -rf "${SVN_DIR}/tags/${VERSION}"
cp -r "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}"

if [[ -d "$ASSETS_SRC" ]]; then
	echo "Syncing WordPress.org assets (banner, icons, screenshots)..."
	mkdir -p "${SVN_DIR}/assets"
	rsync -a --delete \
		--exclude='README.txt' \
		"${ASSETS_SRC}/" "${SVN_DIR}/assets/"
else
	echo "Warning: ${ASSETS_SRC} not found; skipping assets sync." >&2
fi

cd "$SVN_DIR"
svn add --force trunk "tags/${VERSION}" assets 2>/dev/null || true

DELETED=$(svn status | grep '^!' | awk '{print $2}' || true)
if [[ -n "$DELETED" ]]; then
	echo "$DELETED" | xargs svn delete
fi

echo ""
echo "SVN status:"
svn status

if [[ "$DO_COMMIT" == true ]]; then
	echo ""
	echo "Committing to WordPress.org..."
	svn commit -m "Release version ${VERSION}."
	echo "Done."
else
	echo ""
	echo "Review status above, then commit:"
	echo "  cd ${SVN_DIR}"
	echo "  svn commit -m 'Release version ${VERSION}.'"
fi
