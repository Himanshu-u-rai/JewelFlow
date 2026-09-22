# Deploy procedure: stop changing ownership of `.claude/` (backup fix C)

**Status: PROPOSED — for review. Nothing here has been executed. No ownership,
permission or deployment change has been made on any server.**

Backup fix A (`3f1bd85`, local only) and this change are independent. A stops
the backup from reading `.claude/` at all. C stops the deploy procedure from
handing `.claude/` to `dev`. Either can ship without the other. A needs a code
deploy; C needs a change to an operator procedure.

## 1. The step being corrected — and what I could not see

The production deploy procedure changes the owner of `.claude/` to `dev`. **The
text of that step is not in any file I can read.** I searched:

* the repository's tracked files (`STAGING_DEPLOY.md` has the only `chown`, and
  it covers `storage` and `bootstrap/cache`);
* the ignored `output/audit/` deploy scripts in the main checkout (they `chown`
  `public/build` to `www-data` and run composer as `dev`, but never name
  `.claude`);
* earlier session transcripts on this machine.

So the change below is written as a rule plus the exact command form, to apply
to whichever step does it. The reviewer must find that step in the operator's
procedure. The last read-only production evidence
(`output/audit/prod-hotfix/PRODUCTION-DEPLOY-REPORT.md`, 2026-08-07) records
mixed `dev` / `www-data` / `root` ownership and a modified
`.claude/settings.json` in the production tree.

## 2. Why it matters

1. **Backups, until fix A is deployed.** The live backup still walks
   `base_path()`. A `.claude/` that `www-data` cannot read aborts the walk before
   any exclusion is consulted — the traversal failure fix A removes. After A is
   deployed, the backup no longer reads `.claude/`, and this reason goes away.
2. **Privilege.** `.claude/settings.json` can declare hooks: shell commands that
   Claude Code runs when it is used in that directory, as whichever user runs
   it. A `.claude/` writable by `dev` lets `dev` choose commands that run as the
   next user to start an agent there. The production report shows an agent has
   written `.claude/settings.json` in that tree. **Which user ran it is not
   recorded**, so this is a risk inferred from ownership, not an observed
   escalation.

## 3. The change

**Rule:** no deployment or provisioning step changes the owner, group or mode
of `$PROD/.claude` or anything under it.

**Form of the fix, by what the step does:**

| The step does | Replace with |
|---|---|
| `chown -R dev:dev "$PROD/.claude"` (or any path inside it) | delete the step |
| `chown -R dev:dev "$PROD"` (a tree containing `.claude`) | `find "$PROD" -path "$PROD/.claude" -prune -o -exec chown -h dev:dev {} +` |
| `chmod -R …` over a tree containing `.claude` | the same `-path "$PROD/.claude" -prune -o` guard in front of the action |

The `find` form changes nothing else about the step. It still reaches
everything it reached before except `.claude/` itself, and `-h` keeps it from
following symlinks, as `chown -R` does by default.

**Interaction to expect:** `.claude/` is tracked in git. If a release changes
a tracked file under `.claude/`, and `dev` runs the checkout but cannot write
there, the checkout fails loudly. That is safe — nothing is half-applied — but
the operator must then update those files as their owner. It is not a reason to
hand `.claude/` to `dev`. Leaving `.claude/` out of production checkouts
entirely (sparse checkout) would remove the interaction. That is a separate
change and is not proposed here.

## 4. Verification — all read-only

Before the first deploy that uses the corrected procedure:

```bash
stat -c '%U:%G %a %n' /var/www/jewelflow/.claude /var/www/jewelflow/.claude/settings.json
find /var/www/jewelflow/.claude -printf '%u:%g %m %p\n' | sort > /root/claude-ownership.before
```

After that deploy:

```bash
find /var/www/jewelflow/.claude -printf '%u:%g %m %p\n' | sort > /root/claude-ownership.after
diff /root/claude-ownership.before /root/claude-ownership.after && echo "UNCHANGED"
```

The check is `UNCHANGED`. Any difference means some step still touches
`.claude/`.

**Whatever ownership `.claude/` has TODAY is not changed by this procedure
fix.** If earlier deploys have already handed it to `dev`, restoring an owner
is a separate operational change: it needs its own approval, the "before"
listing above as its record, and a rollback that restores exactly that
listing.

## 5. Rollback

This is a change to procedure text. Rolling it back means restoring the
previous text, and it leaves no state on a server to undo. If a later,
separately approved step changes `.claude/` ownership, that step's rollback is:

```bash
# per line of /root/claude-ownership.before: owner:group mode path
while read -r og mode path; do chown -h "$og" "$path"; chmod "$mode" "$path"; done < /root/claude-ownership.before
```

## 6. Limits

* The exact current step was not seen (§1). The fix is exact for the three
  command shapes above, and needs adapting if the step is written differently.
* Nothing was run on a server. The verification commands are proposals.
