# JewelFlows Takeover Integration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reconcile the live Claude UI/navigation work with the landing redesign and independent Retail/Dhiran promotion-recognition candidate, then verify locally. STOP before staging deployment and submit the candidate report for user review, even if every gate passes.

**Architecture:** Start from actual local HEAD `1d01ba440dd43ba9df15d888b2fdf34a23b00049`, preserve the eleven dirty UI files, and apply each handoff as a checked patch in a separate integration worktree. Keep landing presentation isolated to `landing.blade.php`; keep promotion recognition behind its additive migration, existing realm/tenant gates, owner/password/consent checks, and promotion-only tables.

**Tech Stack:** Laravel/PHP, Blade, PostgreSQL, Vite/Node, Playwright, PHPUnit, Git worktrees, existing staging deployment procedure.

**Spec:** READMEs, manifests and patches in the three JewelFlows handoff ZIPs in `/home/himanshu/Downloads`. The initial temporary extraction disappeared after the CLI restart; the ZIPs remain the canonical packet sources. The integration changes are already applied: never replay their patches blindly.

## Global Constraints

- Preserve Claude’s uncommitted files, all existing branches/worktrees, preview databases, and the original branch pointer.
- Treat cloud commit SHAs as reconstructed history; never cherry-pick them.
- Use only the guarded disposable `jewelflow_testing` database for destructive tests.
- Static browser fixtures do not establish Laravel, authentication, database, or deployment correctness.
- Staging only; no production changes.

## Review Focus

- Dirty UI delta remains intact while the UI patch applies; verify the real category/controller/Blade flows and Turbo Back behavior.
- Landing links honor the effective `Realm::dhiranRegisterUrl` configuration without inventing campaign login URLs.
- Promotion recognition remains direct, owner-only, password/consent-bound, realm-scoped, and independently revocable.
- Migration rollback removes only promotion tables and preserves business/financial rows.
- Concurrent exposure, approval, finalization, cancellation, and revocation cannot create duplicate or stale recognition.

### Task 1: Reconcile handoff patches

Apply `codex-ui-delta.patch`, `landing-only.patch`, and `product-promotion.patch` only after their applicability/hash checks pass. Resolve mode-only warnings without changing content, inspect every changed PHP/controller/service/migration/Blade file, and keep the original worktree untouched.

### Task 2: Review and repair implementations

Run PHP syntax checks and targeted static inspection. Fix only concrete issues found in shared code paths: route/middleware/realm/owner authorization, validation, transactions/locks/uniqueness, migration down behavior, Blade expression validity, and UI regressions. Add or adjust focused tests only when a defect requires a regression assertion.

### Task 3: Execute local gates

Install existing dependencies if needed; run PHP lint, Pint on changed PHP, Blade compilation, `npm run build:verify`, focused feature/security tests, full regression tests, real Playwright workflows against the running application, migration up/down/up with before/after business-row checks, and required concurrency workers. Record command, exit status, counts, database target, and unresolved limits.

### Task 4: Staging decision and handoff

Read-only verification of staging baseline SHA, deployment target, effective environment/configuration, database target, migration/rollback plan, and authenticated deployment procedure. Do not deploy: the user requires a pre-staging review checkpoint. Leave actual integration/staging SHAs, measured results, skipped checks, unresolved issues, and deployment state for Claude and review.
