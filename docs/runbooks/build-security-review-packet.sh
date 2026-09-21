#!/usr/bin/env bash
#
# Build the attachable review packet for the multi-tenant security audit.
#
# WHY THIS IS A SCRIPT AND NOT A CHECKED-IN FOLDER OF FILES
# --------------------------------------------------------
# The packet is entirely derived from git plus two runbooks. Committing the
# derived copies would mean every future commit on this branch silently
# invalidates them, and a stale diff in a security review is worse than no diff
# at all. Regenerating takes a second.
#
# WHAT IT DELIBERATELY DOES NOT DO
# --------------------------------
# It does not run the test suite. Measured results belong in the handoff, where
# they are recorded alongside the command that produced them and the commit
# they were measured at; a number this script printed at packaging time would
# look like evidence while being nothing of the kind.
#
# It copies no .env, no database dump, and nothing from storage/. The packet is
# source, migrations, prose and SHAs. There is no customer data in it because
# there is no path by which any could get in.
#
# Usage:  bash docs/runbooks/build-security-review-packet.sh [OUTPUT_DIR]
set -euo pipefail

BASELINE="018b3d810e37d534f498033ab582ee41f3197c27"
OUT="${1:-/tmp/jewelflow-security-review-packet}"

cd "$(git rev-parse --show-toplevel)"

# ONE export SHA, resolved once and used for every subsequent git call.
#
# Everything below refers to $EXPORT_SHA, never to the symbolic `HEAD`. If a
# commit landed midway through a run -- or if this is ever invoked against a
# moving ref -- the symbolic form would let different artifacts in the same
# packet describe different commits. Pinning makes that impossible rather than
# unlikely. Override to export a specific commit: EXPORT_SHA=<sha> bash ...
EXPORT_SHA="$(git rev-parse "${EXPORT_SHA:-HEAD}")"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if ! git cat-file -e "${BASELINE}^{commit}" 2>/dev/null; then
    echo "FATAL: baseline ${BASELINE} is not present in this repository." >&2
    exit 1
fi

rm -rf "$OUT"
mkdir -p "$OUT"/{diffs,migrations,runbooks}

# 1. The full source diff, and a per-commit series so a reviewer can read it in
#    the order it was written rather than as one wall.
git diff "${BASELINE}..${EXPORT_SHA}"                    > "$OUT/diffs/00-full-source.patch"
git diff "${BASELINE}..${EXPORT_SHA}" --stat             > "$OUT/diffs/00-full-source.stat"
git format-patch "${BASELINE}..${EXPORT_SHA}" -o "$OUT/diffs/series" --quiet

# 2. The diffs called out by name in the review request. Split out because
#    "it is in the full patch somewhere" is not the same as supplying them.
git diff "${BASELINE}..${EXPORT_SHA}" -- \
    app/Services/InvoiceSignatureRenderer.php \
    app/Services/SignatureStore.php \
    app/Services/SignatureRelocationLedger.php \
    app/Services/BillTaxPresentation.php        > "$OUT/diffs/10-resolvers.patch"

git diff "${BASELINE}..${EXPORT_SHA}" -- \
    app/Console/Commands/RelocateShopSignatures.php \
    app/Console/Commands/RelocateKarigarInvoiceAttachments.php \
    app/Models/SignatureRelocation.php          > "$OUT/diffs/11-relocation.patch"

git diff "${BASELINE}..${EXPORT_SHA}" -- \
    app/Services/InvoiceAccountingService.php \
    app/Services/InvoiceRenderSnapshotService.php \
    app/Services/QuickBillService.php           > "$OUT/diffs/12-finalization.patch"

git diff "${BASELINE}..${EXPORT_SHA}" -- \
    app/Http/Controllers/Api/Mobile/ \
    app/Http/Middleware/                        > "$OUT/diffs/13-request-path.patch"

git diff "${BASELINE}..${EXPORT_SHA}" -- tests/          > "$OUT/diffs/14-tests.patch"

# 3. Migration bodies in full, not as a diff. A reviewer deciding whether these
#    are safe to apply needs to read the file, not reconstruct it from a patch.
#
#    Read out of the commit, not off disk. `cp` would copy the working tree,
#    so on a dirty tree the migration bodies would silently carry uncommitted
#    edits while every diff beside them stayed commit-to-commit — the packet
#    would be internally inconsistent in exactly the way it warns about.
#
#    ponytail: word-split loop; migration filenames are git-controlled and
#    contain no spaces. Switch to `git diff -z` + `while read -d ''` if that
#    ever stops being true.
for f in $(git diff --name-only --diff-filter=A "${BASELINE}..${EXPORT_SHA}" -- database/migrations/); do
    git show "${EXPORT_SHA}:$f" > "$OUT/migrations/$(basename "$f")"
done

# 4. Prose. The containment procedure travels with the packet because the
#    decision it asks for is the reason the packet exists.
#
#    Out of the commit for the same reason as the migrations: everything in
#    this packet must describe one SHA, including the prose that interprets it.
for f in kyc-public-exposure-containment.md \
         security-multi-tenant-audit-handoff.md \
         signature-migration-release-order.md \
         known-pre-existing-test-debt.md; do
    git show "${EXPORT_SHA}:docs/runbooks/$f" > "$OUT/runbooks/$f"
done

# 5. The SHAs, in full. Abbreviated SHAs are ambiguous across repositories and
#    this packet is meant to be read somewhere else.
{
    echo "# Review packet — JewelFlow multi-tenant security audit"
    echo
    echo "Generated:     $(date -Iseconds)"
    echo "Branch:        ${BRANCH}"
    echo "Baseline:      ${BASELINE}"
    echo "Candidate:     ${EXPORT_SHA}"
    echo
    echo "## Deployed baseline"
    echo
    echo "\`${BASELINE}\` is the *reported* deployed baseline. A commit existing as a"
    echo "local git object is not evidence of what is running on a server — the two"
    echo "are different facts about different machines. See the containment runbook"
    echo "for when the server was last actually observed."
    echo
    echo "## Working tree at generation time"
    echo
    if [ -n "$(git status --porcelain)" ]; then
        echo '```'
        git status --porcelain
        echo '```'
        echo
        echo "**The tree was NOT clean.** The diffs above are commit-to-commit and"
        echo "therefore exclude everything listed here."
    else
        echo "Clean — every change in this packet is committed."
    fi
    echo
    echo "## Commits, oldest first"
    echo
    echo '```'
    git log --reverse --format='%H  %ad  %s' --date=short "${BASELINE}..${EXPORT_SHA}"
    echo '```'
    echo
    echo "## Cumulative diffstat"
    echo
    echo '```'
    git diff --shortstat "${BASELINE}..${EXPORT_SHA}"
    echo '```'
    echo
    echo "## Contents"
    echo
    echo '```'
    find "$OUT" -type f -printf '%P\n' | sort
    echo '```'
    echo
    echo "## Measured test results"
    echo
    echo "Not reproduced here. They are recorded in"
    echo "\`runbooks/security-multi-tenant-audit-handoff.md\` §8 beside the exact"
    echo "command and the commit each was measured at, which is the only form in"
    echo "which a test count means anything."
} > "$OUT/MANIFEST.md"

# 6. Sanitization gate. This ACTUALLY SCANS rather than asserting cleanliness.
#
#    The packet is built from source and prose, so nothing secret should be
#    reachable. That is a claim about a code path, and the whole point of this
#    exercise is that claims about code paths get checked. If the scan ever
#    trips, the ZIP is not written -- failing closed, because a packet is a
#    thing you hand to someone outside the room.
#
#    Deliberately NOT a secret-detection product. It catches the specific ways
#    this repository's own secrets are written, which is what is actually at
#    risk here.
echo "Scanning packet for secrets and customer data..."
SCAN_HITS=0
scan() {
    local label="$1" pattern="$2"
    local hits
    hits="$(grep -rIlE "$pattern" "$OUT" 2>/dev/null || true)"
    if [ -n "$hits" ]; then
        echo "  POSSIBLE ${label}:" >&2
        echo "$hits" | sed 's/^/    /' >&2
        SCAN_HITS=$((SCAN_HITS + 1))
    fi
}

# Laravel/infra secret shapes, as they appear in this repo's config and .env.
#
# APP_KEY requires an ASSIGNMENT, not a bare mention. The first version of this
# pattern matched the word alone and tripped on
# `assertStringNotContainsString('APP_KEY', $response->getContent(), ...)` --
# a test that exists to prove the key does NOT leak. Flagging security-positive
# code as a leak is how a scanner gets switched off, so the pattern was
# narrowed to key material and assignments rather than the gate being relaxed.
scan "APP_KEY"           '(APP_KEY[[:space:]]*=[[:space:]]*[^[:space:]"'"'"']|base64:[A-Za-z0-9+/]{40,})'
scan "DB password"       '(DB_PASSWORD|PGPASSWORD)[[:space:]]*=[[:space:]]*[^[:space:]"'"'"']'
scan "AWS credential"    '(AKIA[0-9A-Z]{16}|aws_secret_access_key)'
scan "Cloudflare token"  '(CLOUDFLARE_API_TOKEN|CF_API_KEY)[[:space:]]*=[[:space:]]*[^[:space:]]'
scan "private key"       'BEGIN (RSA |EC |OPENSSH |PGP )?PRIVATE KEY'
scan "Razorpay live key" 'rzp_live_[A-Za-z0-9]+'
scan "SMTP credential"   'MAIL_PASSWORD[[:space:]]*=[[:space:]]*[^[:space:]]'

# Structural check: nothing may have arrived from these trees at all.
BANNED="$(find "$OUT" -type f \( -name '.env*' -o -name '*.sql' -o -name '*.dump' \
    -o -name '*.sqlite' -o -name '*.pem' -o -name '*.key' -o -name '*.p12' \) 2>/dev/null || true)"
if [ -n "$BANNED" ]; then
    echo "  BANNED FILE TYPE present:" >&2
    echo "$BANNED" | sed 's/^/    /' >&2
    SCAN_HITS=$((SCAN_HITS + 1))
fi

if [ "$SCAN_HITS" -ne 0 ]; then
    echo "FATAL: sanitization scan found ${SCAN_HITS} category/categories above." >&2
    echo "       ZIP NOT WRITTEN. Inspect $OUT before distributing anything." >&2
    exit 2
fi
echo "  clean — 0 findings across 8 categories"

# 7. The attachable artifact itself. A /tmp path is not a deliverable.
ZIP_DIR="${PACKET_ZIP_DIR:-$HOME/Desktop}"
ZIP_PATH="${ZIP_DIR}/jewelflow-security-review-packet-${EXPORT_SHA:0:12}.zip"
mkdir -p "$ZIP_DIR"
rm -f "$ZIP_PATH"
( cd "$(dirname "$OUT")" && zip -qr "$ZIP_PATH" "$(basename "$OUT")" )

echo
echo "Packet written to: $OUT"
echo "Candidate SHA:     ${EXPORT_SHA}"
find "$OUT" -type f | wc -l | xargs echo "Files:            "
echo "ZIP:               $ZIP_PATH"
echo "ZIP size:          $(du -h "$ZIP_PATH" | cut -f1)"
echo "ZIP sha256:        $(sha256sum "$ZIP_PATH" | cut -d' ' -f1)"
