#!/usr/bin/env bash
#
# Publish WordPress Playground blueprint to WordPress.org SVN root assets/blueprints/.
# Also ensures trunk/assets/blueprints/ is present (run full deploy for tag releases).
#
# Usage (from plugin directory):
#   ./scripts/publish-playground-blueprint.sh
#   ./scripts/publish-playground-blueprint.sh --commit
#
set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="$(basename "$PLUGIN_ROOT")"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
if [[ -z "${SVN_DIR:-}" ]]; then
	for candidate in \
		"$(dirname "$PLUGIN_ROOT")/${SLUG}-svn" \
		"$(dirname "$PLUGIN_ROOT")/../${SLUG}-wporg-svn" \
		"$(dirname "$PLUGIN_ROOT")/../${SLUG}-svn"; do
		if [[ -d "$candidate/.svn" ]]; then
			SVN_DIR="$candidate"
			break
		fi
	done
	SVN_DIR="${SVN_DIR:-$(dirname "$PLUGIN_ROOT")/${SLUG}-svn}"
fi
BLUEPRINT_SRC="${PLUGIN_ROOT}/assets/blueprints/blueprint.json"
DO_COMMIT=false

if [[ "${1:-}" == "--commit" ]]; then
	DO_COMMIT=true
fi

if [[ ! -f "$BLUEPRINT_SRC" ]]; then
	echo "ERROR: Missing ${BLUEPRINT_SRC}" >&2
	exit 1
fi

if [[ ! -f "${PLUGIN_ROOT}/blueprint.json" ]]; then
	cp "$BLUEPRINT_SRC" "${PLUGIN_ROOT}/blueprint.json"
fi

if [[ -d "$SVN_DIR/.svn" ]]; then
	svn update "$SVN_DIR"
else
	rm -rf "$SVN_DIR"
	svn checkout "$SVN_URL" "$SVN_DIR"
fi

mkdir -p "${SVN_DIR}/assets/blueprints"
cp "$BLUEPRINT_SRC" "${SVN_DIR}/assets/blueprints/blueprint.json"

mkdir -p "${SVN_DIR}/trunk/assets/blueprints"
cp "$BLUEPRINT_SRC" "${SVN_DIR}/trunk/assets/blueprints/blueprint.json"
cp "$BLUEPRINT_SRC" "${SVN_DIR}/trunk/blueprint.json"
cp "${PLUGIN_ROOT}/readme.txt" "${SVN_DIR}/trunk/readme.txt"

STABLE_TAG="$(grep -E '^Stable tag:' "${PLUGIN_ROOT}/readme.txt" | awk '{print $3}' | tr -d '\r')"
if [[ -n "$STABLE_TAG" && -d "${SVN_DIR}/tags/${STABLE_TAG}" ]]; then
	echo "Syncing blueprint to stable tag tags/${STABLE_TAG}/ ..."
	mkdir -p "${SVN_DIR}/tags/${STABLE_TAG}/assets/blueprints"
	cp "$BLUEPRINT_SRC" "${SVN_DIR}/tags/${STABLE_TAG}/assets/blueprints/blueprint.json"
	cp "$BLUEPRINT_SRC" "${SVN_DIR}/tags/${STABLE_TAG}/blueprint.json"
	cp "${PLUGIN_ROOT}/readme.txt" "${SVN_DIR}/tags/${STABLE_TAG}/readme.txt"
else
	echo "Warning: stable tag directory tags/${STABLE_TAG:-?} not found in SVN checkout." >&2
fi

cd "$SVN_DIR"
svn add --force assets/blueprints assets/blueprints/blueprint.json trunk/assets/blueprints trunk/assets/blueprints/blueprint.json trunk/blueprint.json 2>/dev/null || true
if [[ -n "$STABLE_TAG" && -d "tags/${STABLE_TAG}" ]]; then
	svn add --force "tags/${STABLE_TAG}/assets/blueprints" "tags/${STABLE_TAG}/assets/blueprints/blueprint.json" "tags/${STABLE_TAG}/blueprint.json" 2>/dev/null || true
fi

echo "SVN status:"
svn status assets/blueprints trunk/assets/blueprints "tags/${STABLE_TAG}/assets/blueprints" 2>/dev/null || svn status assets/blueprints trunk/assets/blueprints

if [[ "$DO_COMMIT" == true ]]; then
	svn commit -m "Add WordPress Playground blueprint for live preview."
	echo "Done."
else
	echo "Review above, then: cd ${SVN_DIR} && svn commit -m 'Add WordPress Playground blueprint for live preview.'"
fi
