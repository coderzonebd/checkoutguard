#!/usr/bin/env bash
# Usage: check-version.sh <version> [plugin_dir]
# Fails unless the plugin header, CG_VERSION and the readme Stable tag all equal
# <version>, and readme.txt has a "= <version> =" entry under "== Changelog ==".
# Prerelease versions (with a "-") only need the header and CG_VERSION to match.
set -euo pipefail
WANT="$1"
DIR="${2:-.}"
[[ "$WANT" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "::error::Bad version '$WANT'"; exit 1; }
header=$(sed -nE 's/^[[:space:]*]*Version:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' "$DIR/checkoutguard.php" | head -n1)
const=$(sed -nE "s/.*define\([[:space:]]*'CG_VERSION'[[:space:]]*,[[:space:]]*'([0-9A-Za-z.-]+)'.*/\1/p" "$DIR/checkoutguard.php" | head -n1)
stable=$(sed -nE 's/^Stable tag:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' "$DIR/readme.txt" | head -n1)
echo "header=$header CG_VERSION=$const stable=$stable want=$WANT"
[ "$header" = "$WANT" ] || { echo "::error::Plugin header Version is '$header', want '$WANT'"; exit 1; }
[ "$const" = "$WANT" ]  || { echo "::error::CG_VERSION is '$const', want '$WANT'"; exit 1; }
if [[ "$WANT" != *-* ]]; then
  [ "$stable" = "$WANT" ] || { echo "::error::readme Stable tag is '$stable', want '$WANT'"; exit 1; }
  awk -v want="= $WANT =" '/^== /{insec=($0 ~ /^== Changelog ==/)} insec && $0==want {found=1} END{exit !found}' "$DIR/readme.txt" \
    || { echo "::error::No changelog entry for $WANT inside '== Changelog ==' in readme.txt"; exit 1; }
fi
