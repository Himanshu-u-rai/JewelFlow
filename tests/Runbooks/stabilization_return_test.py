#!/usr/bin/env python3
"""Exercise the script's return preflight with real temporary Git history.

Local guard check only: no deployment, PHP, database or service commands run.
"""
import os
from pathlib import Path
import shlex
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "docs/runbooks/deploy-stabilization.sh"
source = SCRIPT.read_text()
config = source[source.index("RETURN_FLOOR="):source.index("STAGING_DIR=")]
start = source.index("protects() {")
end = source.index('\nelse\n  [ -f "$BUNDLE" ]', start)
gate = source[start:end] + "\nfi\n"
# Keep the checked guard before the backup and maintenance operations.
assert end < source.index('sudo -u postgres pg_dump -Fc')
assert end < source.index('ART down --retry=30')


def run(*args, **kwargs):
    return subprocess.run(args, text=True, capture_output=True, check=True, **kwargs).stdout.strip()


with tempfile.TemporaryDirectory(prefix="jf-return-guard-") as directory:
    repo = Path(directory)
    run("git", "init", "-q", str(repo))
    run("git", "-C", str(repo), "config", "user.name", "Return guard test")
    run("git", "-C", str(repo), "config", "user.email", "return-guard@example.test")
    (repo / "routes").mkdir()
    routes = repo / "routes/mobile.php"

    def commit(label, create=False, edit=False, missing=False):
        if missing:
            routes.unlink()
        else:
            lines = ["<?php"]
            for method, uri, protected in [
                ("post", "/quick-bills", create),
                ("put", "/quick-bills/{quickBill}", edit),
            ]:
                middleware = "'can:sales.create'" + (", 'mobile.idempotency:optional'" if protected else "")
                lines.extend([
                    f"Route::{method}('{uri}', [QuickBillController::class, 'action'])",
                    f"    ->middleware([{middleware}]);",
                ])
            routes.write_text("\n".join(lines) + "\n")
        (repo / "revision.txt").write_text(label + "\n")
        run("git", "-C", str(repo), "add", ".")
        run("git", "-C", str(repo), "commit", "-qm", label)
        return run("git", "-C", str(repo), "rev-parse", "HEAD")

    before_floor = commit("before floor")
    floor = commit("floor", create=True)
    edit_only = commit("edit only", edit=True)
    missing_routes = commit("missing routes", missing=True)
    compatible = commit("both protected", create=True, edit=True)
    current = commit("later documentation", create=True, edit=True)
    claim_log = repo / "claim-query.log"

    def check(label, target, claims, allowed):
        claim_log.unlink(missing_ok=True)
        program = config + "\n" + "\n".join([
            f"RETURN_FLOOR={shlex.quote(floor)}",
            "MODE=return; FROM=$1; TARGET=$2; REPO=$3; CLAIMS_FIXTURE=$4; CLAIM_LOG=$5",
            'G() { git -C "$REPO" "$@"; }',
            'PSQL() { printf "query\\n" >> "$CLAIM_LOG"; printf "%s\\n" "$CLAIMS_FIXTURE"; }',
            'fail() { printf "%s\\n" "$*"; exit 2; }',
            'ok() { :; }',
            gate,
            'echo RETURN_GUARD_PASSED',
        ])
        result = subprocess.run(
            ["bash", "-u", "-o", "pipefail", "-c", program, "guard-test", current, target, str(repo), str(claims), str(claim_log)],
            text=True, capture_output=True, env={**os.environ, "LC_ALL": "C"},
        )
        expected = 0 if allowed else 2
        success = result.returncode == expected and ("RETURN_GUARD_PASSED" in result.stdout) == allowed
        # Claim-table state must not decide whether route protection may be removed.
        success = success and not claim_log.exists()
        print(f"{'PASS' if success else 'FAIL'} {label}")
        if not success:
            print(f"  exit={result.returncode}; claim query={claim_log.exists()}; output={result.stdout.strip()} {result.stderr.strip()}")
        return success

    results = [
        check("refuse losing edit protection with an empty claim table", floor, 0, False),
        check("refuse losing create protection with an empty claim table", edit_only, 0, False),
        check("refuse losing protection with existing claims", floor, 3, False),
        check("refuse missing target route source", missing_routes, 0, False),
        check("refuse targets below the floor", before_floor, 0, False),
        check("refuse a return to the current commit", current, 0, False),
        check("allow a compatible ancestor with no claims", compatible, 0, True),
        check("allow a compatible ancestor with existing claims", compatible, 3, True),
    ]
    print(f"{sum(results)} passed, {len(results) - sum(results)} failed")
    raise SystemExit(0 if all(results) else 1)
