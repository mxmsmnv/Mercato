#!/usr/bin/env bash
set -euo pipefail
version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then echo "Usage: scripts/build-release.sh <version>" >&2; exit 2; fi
if [[ -n "$(git status --porcelain)" ]]; then echo "Release assembly requires a clean tracked worktree." >&2; exit 1; fi
root="$(git rev-parse --show-toplevel)"
dist="$root/dist"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$dist" "$stage/Mercato"
git -C "$root" archive --format=tar HEAD | tar -xf - -C "$stage/Mercato"
composer install --working-dir="$stage/Mercato" --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
php "$stage/Mercato/scripts/verify-runtime.php" --release
find "$stage/Mercato" -name '.DS_Store' -delete
SOURCE_DATE_EPOCH="$(git -C "$root" log -1 --format=%ct)"
export SOURCE_DATE_EPOCH
php -r '$root=$argv[1];$time=(int)getenv("SOURCE_DATE_EPOCH");$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $file)if($file->isFile())touch($file->getPathname(),$time);' "$stage/Mercato"
(cd "$stage" && find Mercato -type f -print | LC_ALL=C sort | zip -X -q "$dist/mercato-$version.zip" -@)
shasum -a 256 "$dist/mercato-$version.zip" > "$dist/mercato-$version.zip.sha256"
echo "$dist/mercato-$version.zip"
