# JewelFlows — continuity and cleanup audit after the release of 8 October 2026

**Version 3, 8 October 2026.** Companion to
`jewelflows-takeover-staging-2026-10-07.md` (version 9; its section 8 is the
release record, its section 9 says where the next task starts). Version 1 of
this audit is commit `e9bdb7d`, version 2 `1287a8e`.

**Since version 3 (handoff version 10, section 10):** the two defects of
section 3 and the first three rows of section 4 (quick-bill idempotency,
`Secure` cookies, the two scheduled commands) are fixed and released at
`bcc336a`; the 22 server worktrees of section 6 are removed after a second
archive of their ignored files; the Redis password named among the old
configuration copies was the placeholder `null`, not a secret. Everything
else below stands as written.

**This audit does not end with "no unresolved work".** The release is
verified and the active working tree is clean. The inventory is finished:
every item below has a disposition. Two of the old branches turned out to
hold fixes for defects that are live in production (section 3), and the
security and tenant batch still has open findings (section 4).

Version 3 corrects version 2 in two places. The uncommitted files in the old
server worktrees were counted again by content, and one worktree that
version 2 showed as clean was not (section 6). The Turbo stash and
`deploy/contact-change`, listed as unique, are not (section 2).

## 1. What is deployed

| | Commit | Migrations | Asset manifest (on disk = served) |
|---|---|---|---|
| Production | `e49e611768e62674de0c4b2a77da42d018b28c30` | 384 | `5d0d0da8e6f1789f…` |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | 384 | `5d0d0da8e6f1789f…` |
| `origin/integration/jewelflows-takeover` | the commit that adds this version | — | — |
| `origin/main` | `dab077435865fed971dbf0ba72256cb51b9753bb`, until draft pull request #2 is merged | — | — |

Production and staging run the same application; they differ in one handoff,
three runbooks and the production check script. The published branch is the
deployed commit plus commits that change only `docs/`.

The evidence for each application change of the release, and the checks that
were not run, are in the handoff (section 8) and unchanged: physical phones,
Safari, Firefox, a restricted role in a browser, the phone width signed in on
production, suppression of the introduction seen in a browser, and the
Dhiran "Sign out" button pressed by a person on production are **NOT RUN**.

## 2. Completed: integrated, superseded or duplicate

Judged by behaviour where the patch differs: the old change was read, the
deployed code was read for the same behaviour, and a test was run where one
existed. Matching lines were not taken as proof.

| Item | What it did | Deployed equivalent, and the evidence | Left over | Done |
|---|---|---|---|---|
| 17 worktrees and 49 branches of the July to October batches | — | each tip on `origin/main` or an ancestor of the deployed commit | none | removed |
| `ui/navigation-batch-3`; `fix/admin-access-mode-consistency` | — | ancestors of the deployed commit / of `origin/main` | none | deleted |
| The original dirty worktree `ui-navigation-batch1` | early versions of the navigation batch | 7 of 11 paths byte-identical to deployed, 4 rewritten by `ea93c46`; all 11 compared with the tag first | none | removed without force; `archive/ui-navigation-batch1-uncommitted-20261008` |
| `ui/nav3-agent1…`, `ui/nav3-agent2…`, `feature/subscription-recovery-lifecycle`, `design/codex-ui-polish`, `fix/admin-restriction-labels`, `hotfix/free-trial-shopless-signup`, `staging/subscription-complete` | — | every commit patch-equivalent to one on the deployed line | none | deleted; `archive/…-20261008` tags |
| `feature/dhiran-ui` (one work-in-progress commit, July) | the Dhiran restyle before it was split | 1,391 of 1,403 added lines deployed | not judged by behaviour: kept under its tag for that reason | deleted; `archive/feature-dhiran-ui-20261008` |
| Stashes "POS customer, one line" and "Codex UI design before split" | — | the one line is deployed; the 65 files equal `archive/design-codex-ui-polish-20261008` | none | dropped; `archive/stash-…` tags |
| **Stash "app.js Turbo fix"** (July) | re-attach the confirmation dialog after a Turbo visit, so that confirm buttons do not go dead until a full reload | the deployed `ensureConfirmDialog()` re-attaches the detached dialog to the live body (commit `1b07aa9`, August). Same behaviour by a different line, which is why only 3 of its 10 lines matched. Read in the code; not exercised in a browser in this pass | none | dropped; `archive/stash-20260705-app-js-turbo-fix` |
| **`deploy/contact-change`** (local copy) | the Account link, see section 3 | both commits are in `design/customers-codex-polish` by patch, and the same tip is on `origin` | see section 3 | local branch deleted; the `origin` branch is untouched |
| `design/customers-codex-polish`, commit "verify-email button disappearing on hover" | a dark hover colour for a white-on-dark button that turned white on white | the page was restyled in July (`159c3d3`): its buttons are dark text on a white card, with no hover rule that changes either. The symptom cannot occur. Read in the stylesheet; not seen in a browser | none | nothing to apply |
| `fix/masters-safety-guards` (1 commit, July) | **removes** the composite foreign key from items to products and keeps only the application check | the deployed line decided the other way: the migration and its test are deployed and the constraint exists in the production database. The delete guard itself is deployed and tested | none. Applying this branch would take an integrity constraint away | branch kept; `archive/fix-masters-safety-guards-20261008`. Recommended: delete the branch |
| Five branches on `origin` not merged into `origin/main` (`design/codex-ui-polish`, `fix/admin-restriction-labels`, `fix/daily-rate-business-date`, `hotfix/free-trial-shopless-signup`, `staging/subscription-complete`) | — | each is one commit patch-equivalent to one on the deployed line | none | left on `origin` |
| Security batch: the published database password (S3-22) | — | rotated in September; the old value no longer matches production's (compared, not shown) | the old value stays in public history, harmless | closed |
| Security batch: origin denies for the five private prefixes | — | 403 at the origin for all five today | see 4 for the three files behind them | closed |

Local repository now: 3 worktrees, 6 branches, no stash, 17 `archive/…` tags
(local only), `release/production-20261008` (published).

## 3. Confirmed defects in production, with a fix waiting on an old branch

Neither was introduced by this release. Neither was fixed in this pass: a
fix is an application change and goes local → staging → production.

| Item | Intended behaviour | In production now | Evidence | Recommended |
|---|---|---|---|---|
| `fix/returns-flash-channel` (`b9ad37c`, August, **local only**) | a shop that has not set up its return policy and opens a return or an exchange is sent to Settings **and told why** | it is sent to Settings and told nothing. Four guards flash the message as `warning`; the layouts put that into a tag nothing reads, and neither the toast script nor the alert component shows it | the branch's own test, run against the deployed code: fails, the message is found only in the unread tag. Most shops on production have no return policy set up, so they are exposed to it | apply `b9ad37c` (it still applies cleanly) in the next fix batch. It also explains the inherited finding that `showToast` ignores its tone |
| `design/customers-codex-polish`, commit `f1988d4` (June; the same change is `fdc39fe` on `origin/deploy/contact-change`) | platform administrators reach "Account Security" (change sign-in email and mobile) from the admin sidebar | the page and its routes are deployed since June (`0e895e6`); nothing links to it. It opens only if the address is typed | no reference to its route anywhere in the deployed views except the page's own forms; the sidebar has no entry and no title for it | re-do the nine-line sidebar link by hand (the old patch no longer applies) in the next fix batch |

`design/customers-codex-polish` is kept for the second row and
`fix/returns-flash-channel` for the first. The other commit of the first
branch that touched the top-navigation layout was reverted on the branch
itself as a mistake.

## 4. Open findings of the security and tenant batch, re-read and re-checked

Source: `docs/runbooks/security-multi-tenant-audit-handoff.md`, sections 0a,
0l, 0m and 0n. The batch closed on 1 October for an agreed scope; closing it
did not close these. "Checked" means looked at again on 8 October,
read-only.

**Confirmed, still open**

| Finding | State on 8 October | Recommended |
|---|---|---|
| Quick-bill creation on the mobile API ignores the idempotency key: the same request twice makes two bills and two payments | unchanged: the deployed controller and its routes have no handling of the key (read; the double booking itself was shown on 1 October and not repeated) | its own task, test first. The highest-value item on this list: it books money twice |
| Session cookies are sent without the `Secure` attribute | checked: `SESSION_SECURE_COOKIE` is false on both environments and neither cookie carries it at the origin | a configuration change by the owner, with a release window |
| Backups exist on the server's own disk only | checked: one local destination, nightly, newest of today; no off-site copy job | owner: choose an off-site destination |
| Two scheduled commands fail | checked in the logs of the last 30 days: `subscription:reconcile-payments` 4 times, last on 7 October; `platform:archive-audit-logs` on 1 October (monthly). `backup:run` has not failed since 26 September | a task each; neither is new |
| Three files remain on the public disk behind the origin denies (`kyc/` 2, `signatures/` 1) | checked: still there, still refused with 403 | relocate to the private disk, as was done for repairs |
| `pageinspect` is installed in the production database | checked: still installed | owner: drop it in a window, or record why it stays |
| POS answers 500 for an item with no price or metal type | not re-tested | a task |
| Review lows: SEC-005 (rotation revert states), SEC-006 (nginx check ignores some blocks), SEC-009 (audit command skips polymorphic columns), SEC-011 remainder | not re-checked | backlog |
| Operator script: the wait after an nginx reload; two `XSRF-TOKEN` cookies on staging pages | not re-checked | backlog |

**Decisions that are the owner's (policy, not code)**

| Finding | Question |
|---|---|
| S3-13: editing a quick bill re-states its supply type under the original number | is an edit a re-issue or a correction |
| S3-16: the scheduled loyalty expiry expires nothing; the repaired mechanism is inactive | activating it removes every overdue balance at once |
| S3-06b, S3-06c: turning on the shopfront publishes every in-stock item; photos stay reachable after it is turned off | per-item publication; whether unpublishing must remove access |
| Repository visibility | checked: this repository is public |
| Quick bills: the app allows issuing with no payment row while the server requires one, and blocks a part payment | which side is right |

**Mobile, from the same handoff** (see also section 5)

| Finding | State |
|---|---|
| A physical Android device check before build 25 goes to shops | **NOT RUN**; the owner's gate |
| The invoice screen's action row under the preview | fixed in code (mobile `8656970`), **not built**, by the owner's decision |
| One `runtimeVersion` for builds with different native layers | checked: the policy is still `appVersion`; no update is to be published while versionCode 16 is unresolved |
| iOS | **NOT RUN**; no iOS build exists |
| Seen on the phone: the bill preview follows the phone's font size; cancelling the print dialog is reported as a failure | not changed |

## 5. Deferred improvements and inherited findings

| Item | State |
|---|---|
| Placement of the Product preferences page | deferred by the owner; nothing about the page was changed |
| Staging is seven documentation-and-test commits behind production | same runtime; left |
| Reports hub shows its Cash Book card to roles the page refuses; Back links 34 to 36 px high on a phone; faint Open POS focus ring; the Add Category dialog does not return focus | unchanged by this release; backlog |
| Cloudflare's analytics beacon is refused by the content policy on every page | console noise; either allow it or switch it off |
| Release tooling never rehearsed: a kill inside `git checkout`; `tree-check` and `baseline` with HEAD at the target and a dirty tree; a full database restore | the return to `aa3a62a` is closed, so recovery is forward repair |

## 6. Recovery material: what is kept, and for how long

Nothing in this section was deleted in this pass.

**The old agent worktrees in the production repository.** 22 worktrees (21
under `.claude/worktrees/` in the production tree, one under `/tmp`), April
to June, 2.2 GB together. Every head is an ancestor of the deployed commit
and the repository has no stash. Their 213 uncommitted files, by content:
98 identical to the deployed file, 41 present somewhere in the repository's
history, **74 found nowhere else** (21 of those a local settings file, the
rest source files in intermediate states). Version 2 said 97, 78 and 37, from
a comparison of paths, and showed the `/tmp` worktree as clean because git
had refused to read it; it has one uncommitted file.

- **Archive made and verified:** every uncommitted file, a patch and the
  state of each worktree, in one tarball readable by root only, beside the
  release evidence on the server. All 213 files were read back out of the
  tarball and compared with the manifest: 213 equal, 0 different.
- **Nothing depends on the directories:** no process has a working
  directory, executable, mapped file or open file in them; no web server,
  service, scheduler or log-rotation configuration names them; the web server
  answers 404 for the path.
- They also hold ignored copies of configuration and of uploaded files. What
  those are and who can read them was reported to the owner directly.
- **Recommended:** the owner approves removal with `git worktree remove` per
  worktree, in a quiet hour. Not done here: it changes the production host by
  hand.

**Retention plan**

| Material | Where | Keep until |
|---|---|---|
| The release window's database dump. **The only verified recovery copy from before the release**: restored in isolation during the window, 144 of 144 tables identical; its hash was checked again today | server, root only | at least 30 days after the release (8 November 2026), **and** until a nightly backup made after the release has itself been restored and checked, **and** an off-site copy exists. Not before all three |
| The pre-release `.env` and configuration cache beside it | server, root only | the same day as the dump, not later: they hold live secrets and can be rebuilt from the running configuration |
| Preflight, rehearsal and run logs of the release (schema only, no rows) | server, root only | until the release is signed off; then they may go |
| Staging's dump and `.env` copy | server, root only | until the next staging release replaces them |
| The archive of the old worktrees | server, root only | 90 days after the worktrees are removed |
| Nightly backups | server, application storage | the current series is pruned by the backup package. An older series under the application's previous name (June and July, 14 GB) is no longer pruned by anything: the owner decides |
| Evidence of earlier batches and loose staging dumps of July to September in root's home | server | owner's decision; none is the only copy of production data |
| Review packets and evidence folders | local, beside the active worktree | until pull request #2 is merged |
| `archive/…` tags (17) | local repository only, on purpose | 90 days, or the owner's decision. Three kept branches hold commits that exist nowhere else: `fix/returns-flash-channel`, `design/customers-codex-polish`, `fix/masters-safety-guards` |

**The mobile application repository** (read only; no build, no release)

| Item | Finding | Disposition |
|---|---|---|
| Release line | `integration/security-audit` = `origin/rebrand/jewelflows-mobile` = `8656970`: the source of build 25 plus the invoice-screen fix | the branch to continue from; nothing to do now |
| 14 other local branches | all contained in the release line by patch, except `audit/pos-parity` (one documentation commit, also on `origin`) | keep; nothing unique is at risk |
| The owner's checkout (`rebrand/jewelflows-mobile`, `4f10a3b`) | its three commits are in the release line under other SHAs; one modified file (`src/utils/storage.ts`: a browser-storage fallback so the web preview can start; not for a shipped build), an untracked `babel.config.js`, a note, a launch file and 16 screenshots of August | the owner's; untouched. The old checklist step (rebase the checkout onto the release line, keeping `storage.ts`) is still to do |
| One stash, "dirty work before shop-access device test" (June) | 8 files; none of its versions exists anywhere in that repository's history | **unique**; kept |
| Two extra worktrees | clean | keep |

**The owner's untracked files in the main web checkout**

Eight reports of July to September, one word list, and three folders of
naming research (24 files) of September. None is on any branch; none
contains anything that looks like a credential. The reports are records of
past test rounds, each followed by a later passing round or by merged work;
their findings were not re-verified here. One masks a shop phone and an
operator email. **Kept untouched.** Recommended: the owner moves them out of
the checkout into a private folder; they should not go into this public
repository as they are.

**Local databases of unknown ownership**

| Database | Finding | Disposition |
|---|---|---|
| `jewelflow_test_mobile_returns` | owned by its own test role; it is the test database the owner's checkout is configured to use | **in use**: keep |
| `jewelflow_test_pre_merge` | 125 tables at the schema of early August, one shop, one user, two invoices, newest row of 21 August; no connection; named by no file, configuration or note | purpose unconfirmed: kept. The owner decides |

## 7. Cleanup performed (all passes)

Only `git worktree remove` and `git branch -d` without force, except that a
branch whose commits are patch-equivalent or superseded was deleted after its
archive tag was verified to point at the same commit. No `reset --hard`, no
`clean`, nothing merged to tidy the list, nothing deleted on `origin`.

- Removed: 18 worktrees and one stale worktree entry.
- Deleted: 60 local branches; 6 remain.
- Dropped: all 3 stashes, each after its tag was created and compared.
- Created: 17 `archive/…` tags and `release/production-20261008`.
- Launch entries: four obsolete entries of a removed preview rig and one
  temporary entry. The owner's two remain.
- Stopped: one temporary local server of my own. The development server on
  port 8000 belongs to another project and was left alone.
- On the server: two read-only scripts and the archive directory were added
  under the release evidence. The production tree, its repository, the
  database, the web server and DNS were not changed.

## 8. Decisions waiting for the owner

1. Merge draft pull request #2 (it brings `main` to the release).
2. Fix the two confirmed defects of section 3 in a small batch, or defer them.
3. The quick-bill idempotency defect: schedule it.
4. Remove the old server worktrees, now that the archive exists.
5. The retention plan of section 6, the older backup series and an off-site
   destination.
6. `Secure` on session cookies, `pageinspect`, the three files on the public
   disk, repository visibility.
7. The four policy questions of section 4.
8. Delete `fix/masters-safety-guards` (tagged); push or drop the two other
   kept branches.
9. The mobile stash, the owner's notes, the database `jewelflow_test_pre_merge`.
10. One observation about the hosting set-up and one about old copies of
    configuration on the server were given to the owner directly and are not
    written here, because this repository is public.

## 9. Where the next task starts

Handoff, section 9: worktree
`/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`, branch
`integration/jewelflows-takeover`, at the commit that adds this version,
level with `origin/integration/jewelflows-takeover`, which is the head of
draft pull request #2.
