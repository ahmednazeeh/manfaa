#!/usr/bin/env bash
#
# Publish the built APKs to https://manfaa.app/app/.
#
# Copying the files was never the hard part — keeping the PAGE honest was.
# The version and size beside each download were hand-typed, so every release
# had to remember to edit them, and on 2026-08-20 one did not: the binaries
# were the new build while the page still advertised the previous version,
# which reads exactly like a deploy that did not happen.
#
# Both numbers are now read back OUT of the APK that was just published, so
# the page cannot claim a version that is not there.
#
#   scripts/deploy-apks.sh            # build outputs -> /app, page, purge
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC="$ROOT/download/public"
PAGE="$PUBLIC/index.html"
AAPT="${AAPT2:-/opt/android-sdk/build-tools/34.0.0/aapt2}"

publish() {
  local app="$1" name="$2"
  local built="$ROOT/mobile/$app/build/app/outputs/flutter-apk/app-release.apk"
  local target="$PUBLIC/$name"

  [ -f "$built" ] || { echo "  $app: no release build at $built" >&2; exit 1; }

  # manfaa owns its tree since the per-project isolation (2026-08-20); nginx
  # reads /app through a narrow ACL on download/public, not through ownership,
  # and the directory's default ACL grants it on files created here.
  install -o manfaa -g manfaa -m 640 "$built" "$target"

  # install(1) creates then chmods, which drops the directory's default ACL —
  # so nginx (www-data) could not read the APK it had just published and /app
  # served 403 while the page happily advertised the new version. Granted
  # explicitly rather than relying on inheritance.
  setfacl -m u:www-data:r-- "$target"

  # Read back from the PUBLISHED file, not from pubspec: the point is to
  # describe what a visitor will actually download.
  local version size
  version="$("$AAPT" dump badging "$target" | sed -n "s/.*versionName='\([^']*\)'.*/\1/p" | head -1)"
  size="$(( ($(stat -c%s "$target") + 1048576 - 1) / 1048576 ))"

  [ -n "$version" ] || { echo "  $app: could not read versionName" >&2; exit 1; }

  # Rewrite the meta line belonging to THIS app's card. Anchored on the
  # card's own app-name so the two cards can never be swapped.
  python3 - "$PAGE" "$name" "$version" "$size" <<'PY'
import re, sys

page, apk, version, size = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
html = open(page, encoding='utf-8').read()

# The card is the block that links to this apk; its meta line is the last
# app-meta before that link.
link = html.index(f'href="/app/{apk}"')
meta = html.rindex('<p class="app-meta">', 0, link)
end = html.index('</p>', meta)

line = html[meta:end]
line = re.sub(r'\d+&nbsp;MB', f'{size}&nbsp;MB', line)
line = re.sub(r'v\d+\.\d+\.\d+', f'v{version}', line)

open(page, 'w', encoding='utf-8').write(html[:meta] + line + html[end:])
print(f'    page now says v{version}, {size} MB')
PY

  echo "  $app -> $name  v$version  ${size}MB"
}

echo "Publishing:"
publish customer manfaa-customer.apk
publish merchant manfaa-merchant.apk

chown manfaa:manfaa "$PAGE"

# A replaced APK behind a CDN is a replaced APK nobody gets.
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
    --data '{"purge_everything":true}' \
    | python3 -c 'import sys,json; print("Cloudflare purged:", json.load(sys.stdin)["success"])'
else
  echo "CLOUDFLARE_API_TOKEN unset — purge SKIPPED, the CDN will serve the old files" >&2
fi
