#!/usr/bin/env bash
#
# Package the WooCommerce plugin and publish it at https://manfaa.app/app/woocommerce/.
#
#   scripts/build-woocommerce-plugin.sh            # zip -> download/public/woocommerce, manifest
#
# The zip is exactly the plugin's runtime files: no vendor/ (it has no
# runtime Composer dependencies), no tests, no dev config. The version is
# read out of the plugin header so the manifest cannot claim a version the
# zip does not carry — the same discipline as deploy-apks.sh.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/plugins/woocommerce/manfaa-cashback"
OUT="$ROOT/download/public/woocommerce"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

VERSION="$(grep -m1 -E '^ \* Version:' "$SRC/manfaa-cashback.php" | sed -E 's/.*Version:[[:space:]]*//')"
[ -n "$VERSION" ] || { echo "no Version header" >&2; exit 1; }
grep -q "MANFAA_CASHBACK_VERSION', '$VERSION'" "$SRC/manfaa-cashback.php" || { echo "header Version ($VERSION) and MANFAA_CASHBACK_VERSION differ" >&2; exit 1; }
grep -q "^Stable tag: $VERSION" "$SRC/readme.txt" || { echo "readme.txt Stable tag is not $VERSION" >&2; exit 1; }

mkdir -p "$STAGE/manfaa-cashback" "$OUT"
rsync -a --exclude vendor --exclude node_modules --exclude tests --exclude composer.json --exclude composer.lock \
  --exclude phpunit.xml --exclude '.phpunit*' --exclude '.git*' "$SRC/" "$STAGE/manfaa-cashback/"

# Every PHP file must at least parse under the floor PHP the header claims.
find "$STAGE/manfaa-cashback" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

( cd "$STAGE" && rm -f "manfaa-cashback-$VERSION.zip" && zip -qr "manfaa-cashback-$VERSION.zip" manfaa-cashback -x '*.DS_Store' )

install -m 0644 "$STAGE/manfaa-cashback-$VERSION.zip" "$OUT/manfaa-cashback-$VERSION.zip"
cp -f "$OUT/manfaa-cashback-$VERSION.zip" "$OUT/manfaa-cashback.zip"

SIZE="$(stat -c %s "$OUT/manfaa-cashback.zip")"
SHA="$(sha256sum "$OUT/manfaa-cashback.zip" | cut -d' ' -f1)"

cat > "$OUT/manifest.json" <<JSON
{
  "slug": "manfaa-cashback",
  "version": "$VERSION",
  "download_url": "https://manfaa.app/app/woocommerce/manfaa-cashback-$VERSION.zip",
  "latest_url": "https://manfaa.app/app/woocommerce/manfaa-cashback.zip",
  "sha256": "$SHA",
  "size": $SIZE,
  "requires": "6.9",
  "requires_php": "8.1",
  "wc_requires": "9.0",
  "wc_tested": "11.0",
  "released_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

chown -R manfaa:manfaa "$OUT" 2>/dev/null || true
echo "manfaa-cashback $VERSION -> $OUT ($SIZE bytes, sha256 $SHA)"

# A replaced zip behind a CDN is a replaced zip nobody gets (same as the APKs).
if [ -f "$ROOT/.env" ]; then
  # shellcheck disable=SC1091
  set -a; . "$ROOT/.env"; set +a
fi

if [ -n "${CLOUDFLARE_API_TOKEN:-}" ]; then
  zone="$(curl -fsS "https://api.cloudflare.com/client/v4/zones?name=manfaa.app" \
    -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["result"][0]["id"])')"

  curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$zone/purge_cache" \
    -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
    -H "Content-Type: application/json" \
    --data "{\"files\":[\"https://manfaa.app/app/woocommerce/manfaa-cashback.zip\",\"https://manfaa.app/app/woocommerce/manfaa-cashback-$VERSION.zip\",\"https://manfaa.app/app/woocommerce/manifest.json\"]}" \
    | python3 -c 'import sys,json; print("Cloudflare purged:", json.load(sys.stdin)["success"])'
else
  echo "CLOUDFLARE_API_TOKEN unset — purge SKIPPED, the CDN will serve the old files" >&2
fi
