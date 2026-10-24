#!/usr/bin/env bash
# Builds the installable zip of Booking Manager: dist/booking-manager-<version>.zip.
#
#   bin/build-zip.sh                 build the interface, check, package
#   bin/build-zip.sh --skip-assets   package the assets/build already present
#
# The zip holds only what WordPress runs: the main file, uninstall.php, src/, the built
# interface (assets/build), the translations and the licence. No sources of the
# interface, tests, Composer or npm files. Every PHP file is syntax-checked and the
# version in the plugin header must match BKNG_VERSION.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="booking-manager"
cd "$ROOT"

if [[ "${1:-}" != "--skip-assets" ]]; then
	npm ci --no-audit --no-fund
	npm run build
fi

for f in admin.js admin.asset.php style-admin.css public.js public.asset.php style-public.css; do
	[[ -f "assets/build/$f" ]] || { echo "Manca assets/build/$f: esegui npm run build." >&2; exit 1; }
done

HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | head -n1 | tr -d '[:space:]')"
CONST_VERSION="$(sed -n "s/^define( 'BKNG_VERSION', '\([^']*\)' );/\1/p" "$SLUG.php")"
if [[ -z "$HEADER_VERSION" || "$HEADER_VERSION" != "$CONST_VERSION" ]]; then
	echo "Versione incoerente: intestazione '$HEADER_VERSION', BKNG_VERSION '$CONST_VERSION'." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST/assets"

cp "$SLUG.php" uninstall.php LICENSE CHANGELOG.md "$DEST/"
[[ -f readme.txt ]] && cp readme.txt "$DEST/"
cp -R src "$DEST/src"
cp -R assets/build "$DEST/assets/build"
[[ -d languages ]] && cp -R languages "$DEST/languages"

# Nothing of the development environment may slip in.
find "$DEST" \( -name '.DS_Store' -o -name '*.map' -o -name '.git*' \) -exec rm -rf {} +

ERRORS=0
while IFS= read -r -d '' file; do
	if ! php -l "$file" > /dev/null; then
		ERRORS=1
	fi
done < <(find "$DEST" -name '*.php' -print0)
[[ $ERRORS -eq 0 ]] || { echo "Errori di sintassi PHP: zip non creato." >&2; exit 1; }

mkdir -p dist
ZIP="$ROOT/dist/$SLUG-$HEADER_VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG" )

echo "Creato: dist/$SLUG-$HEADER_VERSION.zip"
echo "File:   $(unzip -Z1 "$ZIP" | grep -vc '/$')"
echo "SHA256: $( (sha256sum "$ZIP" 2>/dev/null || shasum -a 256 "$ZIP") | cut -d' ' -f1)"
