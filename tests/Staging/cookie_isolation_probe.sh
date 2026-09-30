#!/usr/bin/env bash
# One cookie jar = one browser. Guest page loads only (no login, no form post):
# loads a production page, visits staging with the SAME jar, then reloads the
# production page. A session survives when the page's CSRF token (bound to the
# session) is unchanged; only a hash of it is printed. Also prints, for every
# cookie staging sets, its name and scope (never its value).
# A page counts only when it is HTTP 200 and carries a token. Guest sessions
# only: a signed-in session is the manual browser check, not this probe.
# Exit 0 = every production session survived; 1 = a staging visit replaced one.
set -u -o pipefail
JAR=$(mktemp); trap 'rm -f "$JAR" "$JAR.h"' EXIT
h() { local t; t=$(grep -oE 'name="csrf-token" content="[^"]+"|name="_token" value="[^"]+"' | head -1)
  if [ -n "$t" ]; then printf %s "$t" | sha256sum | cut -c1-12; else echo NO-TOKEN; fi; }   # no token = proves nothing
ok200() { [[ $(head -c 20 "$JAR.h" 2>/dev/null) =~ ^HTTP/[0-9.]+\ 200 ]]; }   # a 503/403/302 page proves nothing
tok() { local b; b=$(curl -sS -m 20 -b "$JAR" -c "$JAR" -D "$JAR.h" "$1"); if ok200; then h <<< "$b"; else echo NO-TOKEN; fi; }
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
  case " $p1 $p2 $p3 " in *" NO-TOKEN "*) verdict="NO TOKEN on a page (challenge, error or redirect): proves nothing" ;; esac
  [ "$verdict" = SURVIVED ] || fail=1
  echo "[$label] production session across a staging visit: $verdict (token hashes $p1 $p2 -> $p3)"
  echo "$sc" | sed 's/^/    staging set: /'
done

# Cross-presentation: a session cookie taken from one environment and sent to
# the other under that one's own cookie name must not be a session there. At
# home the same cookie, sent twice, keeps one session (same token); away, each
# request starts a new session (tokens differ). Values are never printed.
val() { awk -v n="$1" '$6 == n { v = $7 } END { print v }' "$JAR"; }
tok_with() { local b; b=$(curl -sS -m 20 -D "$JAR.h" -H "Cookie: $2=$3" "$1"); if ok200; then h <<< "$b"; else echo NO-TOKEN; fi; }
: > "$JAR"; tok https://jewelflows.com/login >/dev/null; vp=$(val jewelflows-session)
: > "$JAR"; tok https://staging.jewelflows.com/login >/dev/null; vs=$(val jewelflows-session-staging)
for c in "production|https://jewelflows.com/login|jewelflows-session|$vp|home" \
         "staging|https://staging.jewelflows.com/login|jewelflows-session-staging|$vs|home" \
         "production cookie -> staging|https://staging.jewelflows.com/login|jewelflows-session-staging|$vp|away" \
         "staging cookie -> production|https://jewelflows.com/login|jewelflows-session|$vs|away"; do
  IFS='|' read -r label url name value where <<< "$c"
  [ -n "$value" ] || { echo "[$label] no cookie captured"; fail=1; continue; }
  t1=$(tok_with "$url" "$name" "$value"); t2=$(tok_with "$url" "$name" "$value")
  if [ "$t1" = NO-TOKEN ] || [ "$t2" = NO-TOKEN ]; then r="NO TOKEN on the page: proves nothing"; fail=1
  elif [ "$where" = home ]; then if [ "$t1" = "$t2" ]; then r="one session (as expected)"; else r="NOT a session at home"; fail=1; fi
  elif [ "$t1" != "$t2" ]; then r="not a session there (as expected)"; else r="ACCEPTED as a session"; fail=1; fi
  echo "[$label] same cookie twice -> $t1 / $t2: $r"
done
exit $fail
