#!/usr/bin/env bash
# ============================================================================
# Builds the release assets of ONE commit from a clean export of that commit.
#
#   docs/runbooks/build-assets.sh <commit> <output-directory>
#
# Nothing of the working tree is used except its git objects: not its
# node_modules, not its vendor directory, not its compiled-view cache. The
# stylesheet is generated from tracked templates and scripts only (see
# tailwind.config.js), so any machine builds the same files for a commit.
# Dependencies are installed from package-lock.json with `npm ci`.
#
# Writes <output-directory>/assets-<sha>.tar.gz (byte-for-byte reproducible)
# and prints its SHA-256 and the manifest's, which the release script takes.
# ============================================================================
set -euo pipefail

COMMIT=${1:?usage: build-assets.sh <commit> <output-directory>}
OUT=${2:?usage: build-assets.sh <commit> <output-directory>}
ROOT=$(git rev-parse --show-toplevel)
SHA=$(git -C "$ROOT" rev-parse --verify "$COMMIT^{commit}")
mkdir -p "$OUT"; OUT=$(cd "$OUT" && pwd)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

git -C "$ROOT" archive "$SHA" | tar -x -C "$TMP"
cd "$TMP"

# The build must not read anything a clean export does not hold.
if grep -nE "'\./(storage|vendor|bootstrap/cache|public)/" tailwind.config.js; then
  echo "tailwind.config.js scans a directory that is not tracked source: refusing" >&2; exit 2
fi
GLOBS=0
while IFS= read -r glob; do   # read, never word-split: the shell must not expand these
  dir=${glob%%\**}; GLOBS=$((GLOBS + 1))
  [ -n "$(find "$dir" -type f -name "*${glob##*\*}" -print -quit 2>/dev/null)" ] || { echo "$glob matches nothing in a clean export: refusing" >&2; exit 2; }
done < <(sed -n "/^    content: \[/,/^    \],/p" tailwind.config.js | grep -oE "'\./[^']+'" | tr -d "'")
[ "$GLOBS" -gt 0 ] || { echo "no content globs found in tailwind.config.js: refusing" >&2; exit 2; }

npm ci --no-audit --no-fund --loglevel=error
npm run build >/dev/null

[ -s public/build/manifest.json ] || { echo "no manifest was built" >&2; exit 2; }
for f in $(grep -oE '"file": *"[^"]+"' public/build/manifest.json | sed -E 's/.*"file": *"([^"]+)"/\1/'); do
  [ -s "public/build/$f" ] || { echo "the manifest names $f, which was not built" >&2; exit 2; }
done

# Same bytes for the same commit: fixed order, owner and time; no name or time in the gzip header.
TARBALL="$OUT/assets-$SHA.tar.gz"
tar -C public --sort=name --owner=0 --group=0 --numeric-owner --mtime='2020-01-01 00:00:00Z' -cf - build | gzip -n -9 > "$TARBALL"

echo "commit    $SHA"
echo "tarball   $TARBALL"
echo "sha256    $(sha256sum "$TARBALL" | cut -d' ' -f1)"
echo "manifest  $(sha256sum public/build/manifest.json | cut -d' ' -f1)"
echo "files     $(find public/build -type f | wc -l)"
