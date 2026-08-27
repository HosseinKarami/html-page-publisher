#!/usr/bin/env bash
#
# Build the distribution ZIP for WordPress.org submission.
# Reads .distignore to exclude dev-only files.
#
# Usage: ./build-zip.sh
# Output: html-page-publisher.zip in the repo root.

set -euo pipefail

PLUGIN_SLUG="html-page-publisher"
BUILD_DIR="build"
ZIP_NAME="${PLUGIN_SLUG}.zip"

# Pull the version from the plugin header (strip trailing \r from CRLF files).
VERSION=$(grep -E "^\s*\*\s*Version:" "${PLUGIN_SLUG}.php" \
  | head -n1 \
  | sed -E 's/.*Version:[[:space:]]*//' \
  | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
  echo "ERROR: could not read Version from ${PLUGIN_SLUG}.php" >&2
  exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build + previous zip.
rm -rf "${BUILD_DIR}"
rm -f "${ZIP_NAME}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"

# Sync everything into the build dir, excluding patterns listed in .distignore.
if ! command -v rsync >/dev/null 2>&1; then
  echo "ERROR: rsync is required." >&2
  exit 1
fi

rsync -a ./ "${BUILD_DIR}/${PLUGIN_SLUG}/" \
  --exclude-from=.distignore \
  --exclude="${BUILD_DIR}" \
  --exclude="${ZIP_NAME}"

# Make sure no hidden files snuck in (WP.org rejects them).
HIDDEN=$(find "${BUILD_DIR}/${PLUGIN_SLUG}" -name '.*' -not -name '.' -not -name '..' 2>/dev/null || true)
if [[ -n "${HIDDEN}" ]]; then
  echo "ERROR: hidden files found in build — add them to .distignore:" >&2
  echo "${HIDDEN}" >&2
  exit 1
fi

# Zip it up — WP.org requires the plugin folder to be the top level.
(
  cd "${BUILD_DIR}"
  zip -rq "../${ZIP_NAME}" "${PLUGIN_SLUG}"
)

SIZE=$(du -h "${ZIP_NAME}" | cut -f1)

echo ""
echo "✓ Built: ${ZIP_NAME} (${SIZE})"
echo ""
echo "Next steps:"
echo "  First release   → upload at https://wordpress.org/plugins/developers/add"
echo "  Later releases  → push to SVN tags/${VERSION}/"
