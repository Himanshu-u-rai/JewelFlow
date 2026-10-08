# JewelFlows — continuity and cleanup audit after the release of 8 October 2026

Companion to `jewelflows-takeover-staging-2026-10-07.md` (version 5, section 8
is the release record). Written the same morning, after the release.

**This audit does not end with "no unresolved work". Section 4 is not empty.**

## 1. What is deployed

| | Commit | Migrations | Asset manifest (on disk = served) |
|---|---|---|---|
| Production | `e49e611768e62674de0c4b2a77da42d018b28c30` | 384 | `5d0d0da8e6f1789f…` |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | 384 | `5d0d0da8e6f1789f…` |
| `origin/main` | `dab077435865fed971dbf0ba72256cb51b9753bb` | — | — |

Production and staging run the same application: every runtime path is
identical in the two commits; they differ in documentation, runbooks and
`tests/`. **Neither is on `origin`.** `origin/main` is 27 commits behind what
production runs; the release reached the servers as a git bundle.

Application changes in production since the previous release (`aa3a62a`),
with the evidence each rests on:

| Change | Evidence | Still NOT RUN |
|---|---|---|
| Moved navigation (Tag Printing, Reorder Alerts under Stock; Returns / Exchange under Invoices; back links) | feature tests at the reviewed candidate; staging script through the HTTP kernel, owner and three restricted roles; signed-in browser on staging, owner, 1920 and 390 px | signed in on production; a restricted role in a browser |
| Categories (totals after a save, search and page kept, duplicate refusal, nearest-page fallback) | the same three | signed in on production |
| Message placement on a phone; Open POS touch area | built bundle identical on staging and production; measured in the signed-in browser on staging | a touch device; signed in on production |
| Landing page for both products | guest browser on staging (28) and on production (34) | — |
| Product preferences, recognition across the two products, invalidation on a security change (four tables, fourteen routes) | feature tests and race harnesses at the reviewed candidate; staging script (Retail realm); the check script on PostgreSQL 14 in production mode and inside the production window (18 each) | **the flow in two real browsers; the Dhiran pages in a browser; cookies and log-out per product** |
| The two explicit product addresses | release gates; guest browser on `www` | — |

Later the same morning the owner signed in to both products and navigation,
Categories, preferences, a recognition from request to removal, and log-out
were run on production (handoff, end of section 8).

## 2. Inventory, and what each thing is

Web repository, before cleanup: 22 worktrees, 66 local branches, 3 stashes.
A manifest of every branch tip is kept beside the review packets
(`output/takeover/inventory-before-cleanup-2026-10-08.txt`); every deleted
branch is either on `origin/main` or under an `archive/…-20261008` tag.

**Already incorporated and verified**

- 17 worktrees, each clean, each with its tip on `origin/main` (one of them
  patch-equivalent and archived): the admin-label, admin-access, free-trial,
  historical-sales, subscription and security-audit batches of July to
  October. Removed.
- 49 local branches that are ancestors of `origin/main` or of the deployed
  commit, among them `ui/navigation-batch-1` and `-2`. Deleted.

**Superseded or duplicated, with the evidence**

| Item | Evidence | Reference kept |
|---|---|---|
| The original dirty worktree `ui-navigation-batch1` (`ui/navigation-batch-3`, 8 modified and 3 untracked files) | 7 of the 11 files are byte-identical to the deployed commit; the other 4 are earlier versions of the same code (3, 5, 8 and 1 lines that the reconciling commit `ea93c46` rewrote: array alignment, the message timers, the category toggle binding, and the test for it) | `archive/ui-navigation-batch1-uncommitted-20261008` holds the exact uncommitted state. **The worktree itself is kept**: it is not clean, and nothing is removed by force |
| `ui/nav3-agent1-toast-counts`, `ui/nav3-agent2-stock-invoices` | every commit patch-equivalent to one on the deployed line | `archive/ui-nav3-agent1-…`, `archive/ui-nav3-agent2-…`; branches deleted |
| `feature/subscription-recovery-lifecycle`, `design/codex-ui-polish`, `fix/admin-restriction-labels`, `hotfix/free-trial-shopless-signup`, `staging/subscription-complete` | the same | `archive/…-20261008` tags (four are also on `origin`); branches deleted |

**Unique unfinished work** (kept, untouched; see 4)

- Five branches with commits that are on no deployed line.
- Three stashes of July.
- The mobile application repository.

**Deliberately retained**

- Server, root only: `/root/takeover-production` (15 MB: the window's dump,
  the pre-release `.env` and config cache, every run log and rehearsal) and
  `/root/takeover-staging` (8.5 MB, the same for staging).
- Local: the review packets under `output/takeover/` in the active worktree;
  `jewelflow-worktrees/evidence`, `landing-evidence`, `promotion-evidence`.
- The owner's checkout (`fix/small-batch-20260914`, clean tracked tree) with
  its twelve untracked notes and report folders of July to September. Read by
  name, size and date only; none was opened, moved or removed.
- `main-integration` (`main`), fast-forwarded to `origin/main`.
- Local databases and the launch entries of the owner's checkout: nothing
  dropped or edited. Two test databases and four `uinav-*` launch entries are
  of unconfirmed or lapsed purpose (see 4).
- All 52 branches on `origin`: nothing was pushed or deleted there.

## 3. Cleanup performed

All with `git worktree remove` and `git branch -d`, which refuse unclean or
unmerged work. The seven patch-equivalent branches were deleted only after
their archive tag was verified to point at the same commit.

- Removed: 17 worktrees (about 3 GB), the stale `/tmp/jf-oldctl` entry.
- Deleted: 56 local branches (10 remain). Refused and left: `fix/admin-access-mode-consistency`
  (its tip is on `origin/main`, but its upstream points elsewhere).
- Created: 8 `archive/…-20261008` tags and `release/production-20261008`.
- Stopped: nothing. No JewelFlows preview server or process was running. The
  one development server listening belongs to another project.
- Not touched: production and staging checkouts, any backup, any stash, any
  untracked file, the mobile repository, `origin`.

## 4. Unfinished work and open decisions

1. **The Product preferences page is hard to find** (the owner's finding on
   production): one secondary button in each Settings header and nothing else.
   A placement defect by the owner's own rule; an application change, so it
   waits for a decision and the normal local → staging → production path.
   Signed-in checks on production otherwise passed, recognition included
   (handoff, end of section 8).
2. **The release is not on `origin`.** The 27 commits of the release, and the
   documentation commits after it, exist only in the local repository and as
   a bundle on the server.
   The repository is public; pushing is the owner's decision.
3. **Staging is one docs-and-tests step behind production** (`a87dcd5` against
   `e49e611`, same runtime). Align it, or accept it.
4. **Five branches hold commits that are deployed nowhere:**
   `deploy/contact-change` (2: an Account link in the platform-admin layouts);
   `design/customers-codex-polish` (4: the same, and a Dhiran verify-email
   hover fix whose file differs from the deployed one);
   `feature/dhiran-ui` (1 work-in-progress commit, 25 files, July);
   `fix/masters-safety-guards` (1);
   `fix/returns-flash-channel` (1: removing the `warning` flash channel, which
   five deployed files still use). Unique by patch; whether a later commit did
   the same job another way was checked only where noted.
5. **Three stashes of July** on `feature/opening-balance-root`: a one-line POS
   customer change (its line is in deployed code), a Turbo fix in `app.js`
   (3 of its 10 lines are), and a 65-file design change. Kept.
6. **The original dirty worktree** is superseded and archived but still there.
   Removing it needs force; that was not done.
7. **Inherited findings in the application, unchanged by this release:** the
   Reports hub shows its Cash Book card to roles the page refuses;
   `showToast` ignores the tone it is given; on a phone the Back links on Tag
   Printing, Reorder Alerts and Returns are 34 to 36 px high; the Open POS
   focus ring is faint; closing the Add Category dialog does not return focus
   to its button; Cloudflare's analytics beacon is refused by the content
   policy on every page.
8. **Release tooling never rehearsed:** a kill inside `git checkout` itself;
   `tree-check` and `baseline` with HEAD at the target and a dirty tree; a
   full database restore. The return to `aa3a62a` ends with the first
   preference or consent an owner records.
9. **Evidence that holds secrets or data**, root only on the server: the
   production dump and the pre-release `.env` and config cache in
   `/root/takeover-production/release-20261008T033600Z`; a staging dump and
   `.env` copy in `/root/takeover-staging`. Keep until the release is signed
   off; then decide.
10. **The mobile application repository was not audited beyond a listing**:
    eight local branches 2 to 63 commits ahead of its `origin/main`, three
    worktrees, one stash, and the owner's checkout with a modified
    `src/utils/storage.ts` and 19 untracked files. The standing instructions
    for it (no build until the owner says; the physical-device check before
    build 25) are unchanged.
11. **The owner's twelve untracked notes and reports** in the main checkout
    are neither committed nor archived.
12. Local databases `jewelflow_test_mobile_returns` and
    `jewelflow_test_pre_merge`, and the launch entries `uinav-after`,
    `uinav-before`, `uinav-verify`, `uinav-verify-base` (their preview rig is
    gone): ownership or purpose not confirmed, so left.
13. Six branches on `origin` are not merged into `origin/main`
    (`deploy/contact-change`, `design/codex-ui-polish`,
    `fix/admin-restriction-labels`, `fix/daily-rate-business-date`,
    `hotfix/free-trial-shopless-signup`, `staging/subscription-complete`).
14. Handoffs older than those in `docs/handoffs/` (the security and tenant
    batches) were not re-read for this audit.

## 5. Where the next task starts

- Worktree: `/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`
- Branch: `integration/jewelflows-takeover`, clean
- Its HEAD is the commit that adds this document: the deployed commit
  (`release/production-20261008`, `e49e611…`) plus five commits that change
  only `docs/`.
- `main` (worktree `main-integration`) equals `origin/main`, which is behind
  the deployed commit until item 2 is decided.
