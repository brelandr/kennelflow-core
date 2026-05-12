#!/usr/bin/env bash
# Build kennelflow-core.zip (Hub) for WordPress.org / manual install. Run from this directory: ./create-plugin-zip.sh
set -euo pipefail
cd "$(dirname "$0")"

zip -r kennelflow-core.zip . \
	-x "*/.cursorrules" -x "*/.cursor/*" \
	-x "*/.git/*" -x "*/.github/*" -x "*/.gitignore" -x "*/.gitattributes" \
	-x "*/node_modules/*" -x "*/.DS_Store" \
	-x "*/vendor/*" -x "*/assets/src/*" -x "*/src/*" \
	-x "*/tests/*" -x "*/test-results/*" -x "*/phpunit.xml" -x "*/phpunit.xml.dist" \
	-x "*/playwright.config.js" -x "*/playwright*/*" \
	-x "*/phpcs.xml" -x "*/phpcs.xml.dist" -x "*/phpcs-security.xml" \
	-x "extras/*" -x "*/extras/*" \
	-x "*/.deploy-trigger" -x "*/.deploy-test" \
	-x "*.zip" -x "*.sh" -x "*.md" \
	-x "create-plugin-zip.sh"

echo "Created kennelflow-core.zip"
