#!/usr/bin/env bash
# Builds an upload-ready package in release/volleyorga.zip:
# backend with production dependencies + the built PWA in backend/public.
# Nothing needs to be built on the hosting itself.
#
# The zip has no wrapping folder: unzipped into the folder the domain points
# to it yields .htaccess (forwards everything to backend/public) + backend/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/release"
STAGE="$OUT/package"

rm -rf "$OUT"
mkdir -p "$STAGE"

(cd "$ROOT/frontend" && npm ci && npm run build)

mkdir -p "$STAGE/backend"
tar -C "$ROOT/backend" \
  --exclude './.env' --exclude './vendor' --exclude './node_modules' \
  --exclude './tests' --exclude './storage/logs/*.log' --exclude './database/*.sqlite' \
  --exclude './.phpunit.cache' --exclude './.phpunit.result.cache' --exclude './phpunit.xml' \
  --exclude './storage/framework/cache/data/*' --exclude './storage/framework/views/*.php' \
  --exclude './storage/app/setup-code.txt' \
  -cf - . | tar -C "$STAGE/backend" -xf -

(cd "$STAGE/backend" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet)
# If composer had to clone packages from source, drop their git history.
find "$STAGE/backend/vendor" -name .git -type d -prune -exec rm -rf {} +

cp "$ROOT/deploy/htaccess-root" "$STAGE/.htaccess"
cp "$ROOT/docs/deployment.md" "$STAGE/INSTALLATION.md"

# Zip the contents (incl. .htaccess), not the folder.
(cd "$STAGE" && zip -qr "$OUT/volleyorga.zip" .)
echo "Package: $OUT/volleyorga.zip"
