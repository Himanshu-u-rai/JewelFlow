#!/usr/bin/env bash
# ============================================================================
# Security batch — the steps an OPERATOR runs. Claude prepared them and does
# not run them: they change web-server security configuration, rotate a
# credential, or delete files, which its operating rules reserve for a human.
# Each step backs up first, gates, verifies, and prints its rollback.
# Run ON the VPS as root, from an interactive terminal (it refuses otherwise).
#
#   operator-steps-security-batch.sh kyc-origin-deny
#       S3-01, KYC containment Option ORIGIN-ONLY (kyc-public-exposure-
#       containment.md §4 and §6). PARTIAL: copies already cached at the
#       Cloudflare edge stay reachable until §3 (edge rule) and §5 (purge),
#       which need Cloudflare access.
#   operator-steps-security-batch.sh signatures-origin-deny
#       Option SIG-ORIGIN (handoff §7). Only once production serves the
#       release: the baseline prints signatures through /storage/signatures/.
#       PARTIAL for the same edge reason.
#   operator-steps-security-batch.sh rotate-prod-db-password
#       S3-22. Production's database role uses the password committed in
#       phpunit.xml. Rotates it inside a short maintenance window. The new
#       password is generated here and never printed.
#   operator-steps-security-batch.sh purge-originals <signatures|karigar|purchases>
#       R4–R6, last step. Deletes public-disk originals whose private copy
#       verifies. The command itself refuses rows with no ledger evidence and
#       refuses wholesale if any private copy is missing.
# ============================================================================
set -u -o pipefail
[ -t 0 ] || { echo "REFUSED: run this from an interactive terminal."; exit 64; }
[ "$(id -u)" = 0 ] || { echo "REFUSED: run as root."; exit 64; }

PROD=/var/www/jewelflow
VHOST=/etc/nginx/sites-available/jewelflow
BASE=018b3d810e37d534f498033ab582ee41f3197c27
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
ART() { ( cd "$PROD" && sudo -u www-data php artisan "$@" ); }
confirm() { read -r -p "$1 Type YES to continue: " a; [ "$a" = YES ] || { echo "Stopped. Nothing further changed."; exit 1; }; }
code() { curl -sk -o /dev/null -w '%{http_code}' -m 15 --resolve "$1:443:127.0.0.1" "https://$1$2"; }

origin_deny() {   # $1 = prefix under /storage, $2 = label
  local prefix=$1 label=$2 marker="location ^~ /storage/$1/"
  if grep -qF "$marker" "$VHOST"; then echo "Already applied: $marker"; return 0; fi
  install -d -m 0700 -o root -g root /root/nginx-backups
  local backup="/root/nginx-backups/jewelflow.$STAMP.pre-$label.conf"
  cp -a "$VHOST" "$backup"
  echo "Backup: $backup  sha256 $(sha256sum "$backup" | cut -d' ' -f1)"
  # Insert inside the :443 block, immediately before its first `location / {`.
  awk -v prefix="$prefix" -v label="$label" '
    /listen 443/ { in443 = 1 }
    in443 && !done && $0 ~ /^[[:space:]]*location \/ \{/ {
      print "    # " label " containment: never serve /storage/" prefix "/ from the public tree."
      print "    # ^~ stops regex locations (e.g. \\.php$) from winning for this prefix."
      print "    location ^~ /storage/" prefix "/ {"
      print "        deny all;"
      print "    }"
      print ""
      done = 1
    }
    { print }' "$backup" > "$VHOST.new"
  [ "$(grep -cF "$marker" "$VHOST.new")" = 1 ] || { rm -f "$VHOST.new"; echo "Insertion point not found — nothing changed."; exit 1; }
  diff -u "$backup" "$VHOST.new"
  confirm "Apply this change to $VHOST and reload nginx?"
  cat "$VHOST.new" > "$VHOST" && rm -f "$VHOST.new"
  if ! nginx -t; then cat "$backup" > "$VHOST"; echo "nginx -t failed — the backup was restored; the site never reloaded."; exit 1; fi
  systemctl reload nginx && systemctl is-active --quiet nginx || { echo "reload failed — restore with: cat $backup > $VHOST && nginx -t && systemctl reload nginx"; exit 1; }
  local probe="probe-$STAMP-$RANDOM" failed=0
  for h in jewelflows.com www.jewelflows.com dhiran.jewelflows.com; do
    local deny; deny=$(code "$h" "/storage/$prefix/$probe.jpg")
    local ctrl; ctrl=$(code "$h" "/storage/not-$prefix-$probe.jpg")
    echo "$h: /storage/$prefix/<probe> -> $deny (expect 403); control /storage/<other probe> -> $ctrl (expect 404)"
    [ "$deny" = 403 ] && [ "$ctrl" = 404 ] || failed=1
  done
  [ "$(code jewelflows.com /health)" = 200 ] || failed=1
  if [ "$failed" = 0 ]; then echo "VERIFIED at the origin. The edge is NOT covered (PARTIAL)."; else
    echo "VERIFICATION FAILED. Roll back: cat $backup > $VHOST && nginx -t && systemctl reload nginx"; exit 1; fi
  echo "Rollback, if ever needed: cat $backup > $VHOST && nginx -t && systemctl reload nginx"
}

case "${1:-}" in
  kyc-origin-deny)
    origin_deny kyc S3-01 ;;
  signatures-origin-deny)
    [ "$(git -C "$PROD" rev-parse HEAD)" != "$BASE" ] || { echo "REFUSED: production still serves the baseline, which prints signatures through this prefix."; exit 1; }
    origin_deny signatures SIG-ORIGIN ;;
  rotate-prod-db-password)
    current=$(grep -E '^DB_PASSWORD=' "$PROD/.env" | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
    [ "$(printf %s "$current" | sha256sum)" = "$(printf %s StrongPassword123 | sha256sum)" ] \
      || { echo "The production password is no longer the committed one — nothing to do."; exit 0; }
    install -d -m 0700 /root/env-backups
    cp -a "$PROD/.env" "/root/env-backups/jewelflow.env.$STAMP"
    echo "Backup of .env: /root/env-backups/jewelflow.env.$STAMP (0600, root)"
    confirm "Rotate the production database password (about a minute of maintenance)?"
    NEW=$(openssl rand -hex 24)
    ART down --retry=30 || exit 1
    sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c "ALTER ROLE jewelflow PASSWORD '$NEW'" \
      || { ART up; echo "ALTER ROLE failed — nothing changed."; exit 1; }
    sed -i -E "s#^DB_PASSWORD=.*#DB_PASSWORD=$NEW#" "$PROD/.env"
    unset NEW current
    ART config:cache >/dev/null && systemctl reload php8.2-fpm && systemctl restart jewelflow-production-ops-alerts
    if ART migrate:status >/dev/null 2>&1; then ART up; else
      echo "The application cannot connect. It stays in maintenance. The previous .env is /root/env-backups/jewelflow.env.$STAMP;"
      echo "fix DB_PASSWORD in $PROD/.env to match the role, then: config:cache, reload php8.2-fpm, artisan up."; exit 1; fi
    [ "$(code jewelflows.com /health)" = 200 ] && echo "ROTATED: the application connects with the new password; /health 200." \
      || echo "WARNING: /health did not answer 200 — check before walking away."
    echo "The committed phpunit.xml value is now a local test password only." ;;
  purge-originals)
    case "${2:-}" in
      signatures) CMD=signatures:relocate ;;
      karigar)    CMD=karigar-invoices:relocate-attachments ;;
      purchases)  CMD=purchases:relocate-invoice-images ;;
      *) echo "usage: $0 purge-originals <signatures|karigar|purchases>"; exit 64 ;;
    esac
    echo "== $CMD --verify"; ART "$CMD" --verify || { echo "Verify is not clean — no purge."; exit 1; }
    echo "== $CMD --purge-originals (dry run: lists what would be deleted)"; ART "$CMD" --purge-originals || exit 1
    confirm "Delete exactly the originals listed above (each has a verified private copy)?"
    ART "$CMD" --purge-originals --execute || exit 1
    echo "== $CMD --verify (after)"; ART "$CMD" --verify ;;
  *)
    sed -n '2,30p' "$0"; exit 64 ;;
esac
