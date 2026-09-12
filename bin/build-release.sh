#!/usr/bin/env bash
#
# Build the distributable plugin ZIP.
#
# The plugin has no production Composer dependencies: it talks to Edge through
# WordPress's own HTTP API. So there is nothing to vendor, nothing to prefix,
# and the build is a copy of the sources plus the built JavaScript. Composer is
# a development tool here (PHPUnit, PHPCS) and never ships.
#
# Usage: bin/build-release.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="deens-edge-payments-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$OUT/$PLUGIN_SLUG"

cd "$ROOT"

echo "==> Cleaning $OUT"
rm -rf "$OUT"
mkdir -p "$STAGE"

echo "==> Building JavaScript"
npx wp-scripts build

echo "==> Copying plugin sources"
cp "$ROOT/edge-gateway.php" "$STAGE/edge-gateway.php"
cp -R "$ROOT/includes" "$STAGE/includes"
cp -R "$ROOT/assets" "$STAGE/assets"
for f in readme.txt README.md LICENSE NOTICE.md; do
  [[ -f "$ROOT/$f" ]] && cp "$ROOT/$f" "$STAGE/$f"
done
[[ -d "$ROOT/languages" ]] && cp -R "$ROOT/languages" "$STAGE/languages"

echo "==> Verifying"
fail=0

# The whole point of the migration: no third-party runtime code in the ZIP.
if [[ -e "$STAGE/vendor" ]]; then
  echo "  FAIL: vendor/ must not ship; the plugin has no production dependencies" >&2
  fail=1
fi

# Guards a regression back to the SDK. Both names would be dead references in a
# build with no autoloader.
if grep -rqE '\\(Edge|GuzzleHttp)\\' "$STAGE/edge-gateway.php" "$STAGE/includes" 2>/dev/null; then
  echo "  FAIL: the build still references the Edge SDK or Guzzle" >&2
  fail=1
fi

# Nothing may reach for an autoloader that is not there.
if grep -rq "vendor/autoload.php" "$STAGE/edge-gateway.php" "$STAGE/includes" 2>/dev/null; then
  echo "  FAIL: the build expects a Composer autoloader" >&2
  fail=1
fi

for required in "edge-gateway.php" "includes" "assets/js/frontend/blocks.js"; do
  if [[ ! -e "$STAGE/$required" ]]; then
    echo "  FAIL: missing $required" >&2
    fail=1
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo "==> Build FAILED verification" >&2
  exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "$STAGE/edge-gateway.php" | sed 's/.*Version: *//')"
ZIP="$OUT/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Packaging $ZIP"
( cd "$OUT" && zip -qr "$(basename "$ZIP")" "$PLUGIN_SLUG" )

echo "==> Done: $ZIP ($(du -h "$ZIP" | cut -f1))"
