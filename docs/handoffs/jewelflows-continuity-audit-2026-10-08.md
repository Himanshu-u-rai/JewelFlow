# JewelFlows — continuity and cleanup audit after the release of 8 October 2026

**Version 2, 8 October 2026.** Companion to
`jewelflows-takeover-staging-2026-10-07.md` (version 8; its section 8 is the
release record, its section 9 says where the next task starts). Version 1 of
this audit is commit `e9bdb7d`.

**This audit does not end with "no unresolved work". Section 4 is not empty.**
The release is verified; the active working tree is clean; older work that is
unique and unfinished is kept and named below, not completed.

## 1. What is deployed

| | Commit | Migrations | Asset manifest (on disk = served) |
|---|---|---|---|
| Production | `e49e611768e62674de0c4b2a77da42d018b28c30` | 384 | `5d0d0da8e6f1789f…` |
| Staging | `a87dcd58b8c8e1d1cfff9f24025872b24ab83401` | 384 | `5d0d0da8e6f1789f…` |
| `origin/integration/jewelflows-takeover` | the commit that adds this version | — | — |
| `origin/main` | `dab077435865fed971dbf0ba72256cb51b9753bb` | — | — |

Production and staging run the same application: every runtime path is the
same tree in the two commits; they differ in one handoff, three runbooks and
the production check script. The published branch is the deployed commit plus
commits that change only `docs/`. `origin/main` is an ancestor of all of them
and predates the release.

Application changes in production since the previous release (`aa3a62a`),
with the evidence each rests on:

| Change | Evidence | Still NOT RUN |
|---|---|---|
| Moved navigation (Tag Printing, Reorder Alerts under Stock; Returns / Exchange under Invoices; back links) | feature tests at the reviewed candidate; staging script through the HTTP kernel, owner and three restricted roles; signed-in browser on staging (1920 and 390 px) and on production (owner, desktop) | a restricted role in a browser; phone width signed in on production |
| Categories (totals after a save, search and page kept, duplicate refusal, nearest-page fallback) | the same | — |
| Message placement on a phone; Open POS touch area | built bundle identical on staging and production; measured in the signed-in browser on staging | a touch device |
| Landing page for both products | guest browser on staging (28) and on production (34) | — |
| Product preferences, recognition across the two products, invalidation on a security change (four tables, fourteen routes) | feature tests and race harnesses at the reviewed candidate; staging script; the check script on PostgreSQL 14 in production mode and inside the production window (18 each); on production, in the owner's two signed-in sessions: introduction once, preferences, a request cancelled, a wrong password refused, one recognition from request to removal | suppression of the introduction seen in a browser; Safari, Firefox |
| Sessions independent per product | on production: log out of Retail, Dhiran stays signed in; log out of Dhiran, Retail stays signed in | the Dhiran "Sign out" button pressed by a person on production (the log-out request was sent from the page; the press was verified only on the deployed code run locally) |
| The two explicit product addresses | release gates; guest browser on `www` | — |

## 2. Inventory, and what became of each thing

Web repository before any cleanup: 22 worktrees, 66 local branches,
3 stashes. Now: 3 worktrees, 7 local branches, 1 stash, 16 `archive/…` tags,
1 `release/…` tag. A manifest of every branch tip before cleanup is kept
beside the review packets
(`output/takeover/inventory-before-cleanup-2026-10-08.txt`).

Ancestry misleads here, because much of this work was cherry-picked. So each
item was judged by patch (`git cherry`) and, where the patch differs, by
content: files compared by blob, added lines looked for in the deployed
commit.

**Already integrated** (removed)

| Item | Evidence |
|---|---|
| 17 worktrees of the July to October batches (admin labels, admin access, free trial, historical sales, subscription, security audit) | each clean, each tip on `origin/main` |
| 49 local branches, among them `ui/navigation-batch-1` and `-2` | ancestors of `origin/main` or of the deployed commit |
| `ui/navigation-batch-3` | tip is an ancestor of the deployed commit |
| `fix/admin-access-mode-consistency` | tip is on `origin/main`; its stale upstream setting was the only thing that had kept `git branch -d` from accepting it |

**Superseded** (archived under a tag, then removed)

| Item | Evidence | Reference kept |
|---|---|---|
| The original dirty worktree `ui-navigation-batch1` (8 modified and 3 untracked files) | 7 of the 11 paths are byte-identical to the deployed commit; the other 4 are earlier versions of code the reconciling commit `ea93c46` rewrote. All 11 were compared byte for byte with the tag before anything was touched. The 8 edits were put back to their committed state and the 3 untracked files (identical to deployed files) removed one by one, after which the worktree was removed without force | `archive/ui-navigation-batch1-uncommitted-20261008` (`f6fa6cf`) |
| `ui/nav3-agent1-toast-counts`, `ui/nav3-agent2-stock-invoices` | every commit patch-equivalent to one on the deployed line | `archive/ui-nav3-agent1-…` (`95edfc5`), `archive/ui-nav3-agent2-…` (`ca4a733`) |
| `feature/subscription-recovery-lifecycle`, `design/codex-ui-polish`, `fix/admin-restriction-labels`, `hotfix/free-trial-shopless-signup`, `staging/subscription-complete` | the same | `archive/…-20261008` tags `01b6129`, `fcca869`, `de9d1d0`, `2fc2642`, `47f8ff8` |
| `feature/dhiran-ui` (one work-in-progress commit of July, 25 files) | not patch-equivalent, but 1,391 of its 1,403 added lines are in the deployed commit | `archive/feature-dhiran-ui-20261008` (`b4a27f6`) |
| Stash "POS customer, one line" (July) | its one line is in the deployed commit | `archive/stash-20260705-pos-customer-one-line` (`48d83d8`) |
| Stash "Codex UI design before split" (July, 65 files) | its 65 tracked files are identical to `archive/design-codex-ui-polish-20261008`; its untracked part is 154 browser-tool snapshots, a launch file and a screenshot | `archive/stash-20260703-codex-ui-design-before-split` (`506e8cd`) |

**Unique and unfinished** (kept untouched; none is part of this release)

| Item | What it is | Where it exists |
|---|---|---|
| `deploy/contact-change` (`fdc39fe`, 2 commits, June) | an Account link in the platform-admin layouts | local and `origin` |
| `design/customers-codex-polish` (`fc3e639`, 4 commits not deployed) | the same link, and a Dhiran verify-email hover fix whose file differs from the deployed one | local; 5 commits ahead of its `origin` branch |
| `fix/masters-safety-guards` (`2d54c19`, 1 commit) | 7 of its 30 added lines are in the deployed commit | local; 1 commit ahead of its `origin` branch |
| `fix/returns-flash-channel` (`b9ad37c`, 1 commit) | removes the `warning` flash channel, which five deployed files still use; 7 of its 42 added lines are deployed | **local only** |
| The one remaining stash, "app.js Turbo fix" (July) | 3 of its 10 lines are deployed | local stash, also `archive/stash-20260705-app-js-turbo-fix` (`bceb08a`) |

Whether any of these is still wanted is the owner's call. None touches the
moved navigation, Categories, the landing page or the promotion feature
(read from their diffs; none was run), so none was needed to finish this
release, and none was merged to make the list shorter.

**On `origin`, not merged into `origin/main`** (nothing was deleted there)

`deploy/contact-change` is the unique one above. The other five
(`design/codex-ui-polish`, `fix/admin-restriction-labels`,
`fix/daily-rate-business-date`, `hotfix/free-trial-shopless-signup`,
`staging/subscription-complete`) are each one commit that is
patch-equivalent to a commit on the deployed line: superseded, left in place.

**Found on the server in this pass: old agent worktrees in the production
repository.** The production checkout's git repository carries 22 extra
worktrees: 21 under `.claude/worktrees/` inside the production tree and one
under `/tmp`, from April to June, 1.5 GB, with 39 local branches. The
directory is ignored by git and lies outside the web root. Every one of the
39 branches is an ancestor of the deployed commit. Every worktree has
uncommitted files: of 212 in all, 97 are identical to the deployed file,
78 differ from it and 37 exist nowhere in the deployed commit (21 of those
are a local settings file). They were read, not touched: they are not part
of this release, some may be unique, and removing them means changing the
production host by hand.

**Deliberately retained**

- Production and staging checkouts, untouched.
- Server, root only: `/root/takeover-production` (15 MB: the window's dump,
  the pre-release `.env` and config cache, every run log and rehearsal) and
  `/root/takeover-staging` (8.5 MB, the same for staging).
- `release/production-20261008` (published) and the 16 `archive/…` tags
  (local only, see handoff section 9).
- Local: the review packets under `output/takeover/` in the active worktree;
  `jewelflow-worktrees/evidence`, `landing-evidence`, `promotion-evidence`.
- The owner's checkout (`/home/himanshu/Desktop/JewelFlow`,
  `fix/small-batch-20260914`, clean tracked tree) with its twelve untracked
  notes and report folders. Read by name, size and date only.
- `main-integration` (`main`, equal to `origin/main`).
- The mobile application repository, whole (see 4).

## 3. Cleanup performed

Only `git worktree remove` and `git branch -d` without force, except that a
branch whose commits are patch-equivalent or superseded by content was
deleted after its archive tag was verified to point at the same commit.
No `reset --hard`, no `clean`, no forced worktree removal, nothing merged to
tidy the list.

- Removed: 18 worktrees (about 3 GB) and the stale `/tmp/jf-oldctl` entry.
- Deleted: 59 local branches; 7 remain.
- Dropped: 2 of the 3 stashes, each after its tag was created and compared.
- Created: 16 `archive/…` tags and `release/production-20261008`.
- Launch entries in the owner's checkout: the four `uinav-*` entries, whose
  preview rig no longer exists, and one temporary entry made and removed
  during this pass. `laravel-preview` and `vite` are the owner's and remain.
- Stopped: one temporary local server started in this pass to reproduce the
  log-out. No other JewelFlows preview process was running. The development
  server on port 8000 belongs to another project and was left alone.
- Not touched: `origin` branches, the mobile repository, any database, any
  backup, any untracked file of the owner's.

## 4. Unfinished work and open decisions

1. **`origin/main` predates the release.** The release is published on its
   branch; `main` has not been moved. It is a fast-forward.
2. **Product preferences is hard to find** (the owner's finding): one
   secondary button in each Settings header. Deferred by the owner.
3. **The five unique items of section 2**, three of them holding commits that
   exist only in the local repository.
4. **The old agent worktrees in the production repository** (section 2).
5. **Staging is seven documentation-and-test commits behind production**,
   with the same runtime. Left as it is.
6. **Checks NOT RUN:** physical phones; Safari; Firefox; a restricted role in
   a browser; the phone width signed in on production; suppression of the
   introduction seen in a browser; the Dhiran "Sign out" button pressed by a
   person on production.
7. **Release tooling never rehearsed:** a kill inside `git checkout` itself;
   `tree-check` and `baseline` with HEAD at the target and a dirty tree; a
   full database restore. The return to `aa3a62a` is closed: two owners have
   recorded preferences, so recovery is forward repair.
8. **Inherited findings in the application, unchanged by this release:** the
   Reports hub shows its Cash Book card to roles the page refuses;
   `showToast` ignores the tone it is given; on a phone the Back links on Tag
   Printing, Reorder Alerts and Returns are 34 to 36 px high; the Open POS
   focus ring is faint; closing the Add Category dialog does not return focus
   to its button; Cloudflare's analytics beacon is refused by the content
   policy on every page.
9. **Evidence that holds secrets or data**, root only on the server: the
   production dump and the pre-release `.env` and config cache under
   `/root/takeover-production`; a staging dump and `.env` copy under
   `/root/takeover-staging`. Keep until the release is signed off; then
   decide.
10. **The mobile application repository was not audited beyond a listing**:
    15 local branches, three worktrees (one beside the web worktrees), one
    stash, and the owner's checkout with one modified file
    (`src/utils/storage.ts`) and 19 untracked. The standing instructions for
    it (no build until the owner says; the physical-device check before
    build 25) are unchanged.
11. **The owner's twelve untracked notes and reports** in the main checkout
    are neither committed nor archived.
12. Local databases `jewelflow_test_mobile_returns` and
    `jewelflow_test_pre_merge`: ownership not confirmed, so left.
13. Handoffs older than those in `docs/handoffs/` (the security and tenant
    batches) were not re-read for this audit.
14. One observation about the hosting set-up was given to the owner directly
    on 8 October and is not written here, because this repository is public.

## 5. Where the next task starts

Handoff, section 9: worktree
`/home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover`, branch
`integration/jewelflows-takeover`, at the commit that adds this version.
