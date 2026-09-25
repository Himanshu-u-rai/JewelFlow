#!/usr/bin/env bash
# One cookie jar = one browser. Guest page loads only (no login, no form post):
# loads a production page, visits staging with the SAME jar, then reloads the
# production page. A session survives when the page's CSRF token (bound to the
# session) is unchanged; only a hash of it is printed. Also prints, for every
# cookie staging sets, its name and scope (never its value).
# Exit 0 = every production session survived; 1 = a staging visit replaced one.
set -u -o pipefail
JAR=$(mktemp); trap 'rm -f "$JAR" "$JAR.h"' EXIT
tok() { curl -sS -m 20 -b "$JAR" -c "$JAR" -D "$JAR.h" "$1" \
  | grep -oE 'name="csrf-token" content="[^"]+"|name="_token" value="[^"]+"' | head -1 | sha256sum | cut -c1-12; }
set_cookies() { tr -d '\r' < "$JAR.h" | grep -i '^set-cookie:' \
  | sed -E 's/^[Ss]et-[Cc]ookie: ([^=]+)=[^;]*(.*)/\1\2/; s/; ?expires=[^;]*//I; s/; ?max-age=[^;]*//I' | sort -u; }
fail=0
for pair in "https://jewelflows.com/login|https://staging.jewelflows.com/login|tenant" \
            "https://jewelflows.com/admin/login|https://staging.jewelflows.com/admin/login|platform-admin" \
            "https://dhiran.jewelflows.com/login|https://staging.jewelflows.com/login|dhiran"; do
  IFS='|' read -r prod stag label <<< "$pair"
  : > "$JAR"
  p1=$(tok "$prod"); p2=$(tok "$prod")
  s=$(tok "$stag"); sc=$(set_cookies)
  p3=$(tok "$prod")
  verdict=$([ "$p1" = "$p2" ] && [ "$p2" = "$p3" ] && echo SURVIVED || echo REPLACED)
  [ "$p1" = "$p2" ] || verdict="NO-BASELINE (production token changed without staging)"
  [ "$verdict" = SURVIVED ] || fail=1
  echo "[$label] production session across a staging visit: $verdict (token hashes $p1 $p2 -> $p3)"
  echo "$sc" | sed 's/^/    staging set: /'
done
exit $fail
