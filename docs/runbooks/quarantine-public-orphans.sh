#!/usr/bin/env bash
# ============================================================================
# Take orphaned private attachments out of the web root.
#
# storage/app/public/{karigar-invoices,purchases}/ are served by nginx to anyone
# who has the URL. The application stopped writing and linking them (S3-02,
# S3-03: private disk, authenticated route), but files with no database row
# were left behind, and the row-driven relocation commands never see those.
#
# This moves every such file to storage/app/private/quarantine/public-orphans/
# (same relative path, same owner, same bytes: a rename on one file system, with
# the sha256 checked before and after) and writes a manifest beside them. With
# the file gone from the web root the origin has nothing to serve for ANY form
# of its URL — query string, host or encoding — which a deny on one spelling of
# the path, or a purge of one cache key, does not give. A file a database row
# still names is left where it is and reported: that one belongs to the
# relocation command, which updates the row.
#
#   quarantine-public-orphans.sh plan      read-only: what is there, what would move
#   quarantine-public-orphans.sh move      move the orphans (asks for YES)
#   quarantine-public-orphans.sh verify    read-only: nothing left, every copy intact,
#                                          every host answers 404 for every moved
#                                          file and for a query-string form of it,
#                                          at the origin and through the edge
#
# To put a file back: mv it from the quarantine to the path in MANIFEST.tsv.
# Nothing is deleted, here or ever, by this script.
# ============================================================================
set -u -o pipefail
umask 077

PROD=/var/www/jewelflow
DB=jewelflow
WEB=www-data
HOSTS="jewelflows.com www.jewelflows.com dhiran.jewelflows.com"
# directory = table.column that names its files
PREFIXES="karigar-invoices=karigar_invoices.invoice_file_path purchases=stock_purchases.invoice_image"
ACCESS_LOG_GLOB="/var/log/nginx/access.log*"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

say()  { printf '%s\n' "$*"; }
ok()   { printf 'ok    %s\n' "$*"; }
stop() { printf '!!!!! FAILED: %s\n      STATE:    %s\n' "$1" "$2"; exit 1; }
sha()  { sha256sum "$1" | cut -c1-64; }
PGSU() { sudo -u postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -d "$DB" "$@"; }
# How many rows name this file (exact path, or any path ending in its name).
refs() { PGSU -c "select count(*) from ${1%%.*} where ${1##*.} = '$2' or ${1##*.} like '%/${2##*/}'"; }
origin() { curl -sk -o /dev/null -m 15 -w '%{http_code}' --resolve "$1:443:127.0.0.1" "https://$1$2"; }   # straight to nginx
edge()   { curl -s -o /dev/null -m 20 -w '%{http_code}' "https://$1$2"; }                                  # through Cloudflare
logged() { zcat -f $ACCESS_LOG_GLOB 2>/dev/null | awk -v p="$1" 'index($7, p) == 1 { who = ($1 == "127.0.0.1" || $1 == "::1") ? "this-host" : "other"; split($4, t, ":"); print substr(t[1], 2), who, $9 }' | sort | uniq -c; }

pub() { printf '%s' "$PROD/storage/app/public"; }
dest() { printf '%s' "$PROD/storage/app/private/quarantine/public-orphans"; }
# "dir/…/name<TAB>table.column" for every file under the prefixes.
files() {
  local e d f
  for e in $PREFIXES; do
    d=${e%%=*}
    [ -d "$(pub)/$d" ] || continue
    while IFS= read -r f; do printf '%s\t%s\n' "$f" "${e#*=}"; done < <(cd "$(pub)" && find "$d" -type f | sort)
  done
}
# A name goes into a query and a URL: anything but plain characters stops the run (in this shell, not a subshell).
names_ok() {
  local e bad=0
  for e in $PREFIXES; do
    [ -d "$(pub)/${e%%=*}" ] || continue
    bad=$((bad + $(cd "$(pub)" && find "${e%%=*}" -type f | grep -cvE '^[A-Za-z0-9._/-]+$')))
  done
  [ "$bad" = 0 ] || stop "$bad file name(s) under the prefixes have characters this script will not put in a query" "nothing changed"
}

plan() {
  local f col n orphans=0 held=0
  names_ok
  say "web root: $(pub)"
  while IFS=$'\t' read -r f col; do
    [ -n "$f" ] || continue
    n=$(refs "$col" "$f") || stop "could not ask the database about a file" "nothing changed"
    if [ "$n" = 0 ]; then orphans=$((orphans + 1)); say "      orphan   ${f%%/*}/…  $(stat -c '%s bytes, modified %y' "$(pub)/$f" | cut -c1-36)  sha256 $(sha "$(pub)/$f" | cut -c1-12)"
    else held=$((held + 1)); say "      NAMED BY $n ROW(S) in $col: ${f%%/*}/… stays (use the relocation command)"; fi
  done < <(files)
  say "      $orphans orphan file(s) would move to $(dest); $held file(s) named by a row stay"
  local e
  for e in $PREFIXES; do
    say "      requests logged for /storage/${e%%=*}/ (count, day, from, status):"; logged "/storage/${e%%=*}/" | sed 's/^/        /'
  done
  say "      oldest line in the access logs: $(zcat -f $(ls -tr $ACCESS_LOG_GLOB 2>/dev/null | head -1) 2>/dev/null | head -1 | awk '{print $4}' | tr -d '[')"
}

move() {
  local f col n a b moved=0 manifest
  names_ok
  mkdir -p "$(dest)" && chown "$WEB:$WEB" "$PROD/storage/app/private/quarantine" "$(dest)" && chmod 700 "$PROD/storage/app/private/quarantine" "$(dest)" \
    || stop "could not create $(dest)" "nothing moved"
  manifest="$(dest)/MANIFEST.tsv"
  [ -f "$manifest" ] || printf 'moved_at_utc\toriginal_path_under_storage_app_public\tsha256\tbytes\n' > "$manifest"
  while IFS=$'\t' read -r f col; do
    [ -n "$f" ] || continue
    n=$(refs "$col" "$f") || stop "could not ask the database about a file" "$moved file(s) moved so far; the rest untouched"
    [ "$n" = 0 ] || { say "      left in place (named by $n row(s)): ${f%%/*}/…"; continue; }
    [ ! -e "$(dest)/$f" ] || stop "the quarantine already holds a file at that path" "$moved file(s) moved so far; this one untouched"
    a=$(sha "$(pub)/$f")
    mkdir -p "$(dirname "$(dest)/$f")" && mv -n "$(pub)/$f" "$(dest)/$f" \
      || stop "could not move a file" "$moved file(s) moved so far; this one is where it was or in the quarantine, never deleted"
    b=$(sha "$(dest)/$f")
    [ "$a" = "$b" ] && [ ! -e "$(pub)/$f" ] || stop "a moved file does not match its original" "it is at $(dest)/$f; nothing was deleted"
    printf '%s\t%s\t%s\t%s\n' "$(date -u +%FT%TZ)" "$f" "$b" "$(stat -c %s "$(dest)/$f")" >> "$manifest"
    moved=$((moved + 1)); ok "moved ${f%%/*}/… (sha256 $(printf %s "$b" | cut -c1-12), same before and after)"
  done < <(files)
  # Every directory and file in the quarantine: the web user's, and nobody else's
  # (mkdir -p made the intermediate ones root's, 755, on the first run of 2026-10-01).
  chown -R "$WEB:$WEB" "$(dest)" && find "$(dest)" -type d -exec chmod 700 {} + && chmod 600 "$manifest" \
    || stop "could not set the quarantine's ownership" "$moved file(s) moved; nothing deleted"
  ok "MOVED $moved file(s) to $(dest); manifest $manifest"
}

verify() {
  local rc=0 f col n at path sum bytes h r q manifest; manifest="$(dest)/MANIFEST.tsv"
  names_ok
  n=0; while IFS=$'\t' read -r f col; do [ -n "$f" ] || continue; [ "$(refs "$col" "$f")" = 0 ] && n=$((n + 1)); done < <(files)
  if [ "$n" = 0 ]; then ok "no orphan file is left under the web root's $(for e in $PREFIXES; do printf '%s/ ' "${e%%=*}"; done)"; else say "!!!!! $n orphan file(s) still under the web root"; rc=1; fi
  [ -f "$manifest" ] || { say "!!!!! no manifest at $manifest"; return 1; }
  n=0
  while IFS=$'\t' read -r at path sum bytes; do
    [ "$at" = moved_at_utc ] && continue
    n=$((n + 1))
    if [ -f "$(dest)/$path" ] && [ "$(sha "$(dest)/$path")" = "$sum" ] && [ ! -e "$(pub)/$path" ]; then :; else say "!!!!! file $n: the quarantined copy is missing or changed, or the original is back"; rc=1; continue; fi
    q="?v=$RANDOM$RANDOM"
    for h in $HOSTS; do
      r="$(origin "$h" "/storage/$path") $(origin "$h" "/storage/$path$q")"
      [ "$r" = "404 404" ] || { say "!!!!! file $n on $h at the origin: $r (expected 404 404)"; rc=1; }
      r="$(edge "$h" "/storage/$path" | cut -d' ' -f1) $(edge "$h" "/storage/$path$q" | cut -d' ' -f1)"
      [ "$r" = "404 404" ] || { say "!!!!! file $n on $h through the edge: $r (expected 404 404)"; rc=1; }
    done
  done < "$manifest"
  [ "$rc" = 0 ] && ok "$n quarantined file(s): copy intact (sha256), gone from the web root, and 404 on $(wc -w <<< "$HOSTS") hosts for the plain URL and a query-string form, at the origin and through the edge"
  # Positive controls: what must still be served is, and what was denied before still is.
  f=$(cd "$(pub)" && find shop-logos items -type f 2>/dev/null | head -1)
  [ -n "$f" ] || { say "!!!!! no public catalogue file to use as a positive control"; rc=1; }
  for h in $HOSTS; do
    r="login=$(origin "$h" /login) public=$(origin "$h" "/storage/$f") kyc=$(origin "$h" "/storage/kyc/probe-$STAMP.jpg") signatures=$(origin "$h" "/storage/signatures/probe-$STAMP.jpg")"
    if [ "$r" = "login=200 public=200 kyc=403 signatures=403" ]; then ok "$h controls: /login 200, a public catalogue file 200, /storage/kyc/ and /storage/signatures/ still 403"
    else say "!!!!! $h controls: $r"; rc=1; fi
  done
  return "$rc"
}

[ "${QUARANTINE_LIB:-}" = 1 ] && return 0 2>/dev/null   # sourced by the tests: functions only
[ "$(id -u)" = 0 ] || { echo "REFUSED: run as root."; exit 64; }
case "${1:-}" in
  plan)   plan ;;
  move)   plan; read -r -p "Move these orphan files out of the web root? Type YES: " a; [ "$a" = YES ] || { say "Stopped. Nothing changed."; exit 1; }; move ;;
  verify) verify ;;
  *) sed -n '2,27p' "$0"; exit 64 ;;
esac
