# Contributing to JewelFlow

## Before You Write Code

Read [CONSTITUTION.md](CONSTITUTION.md) before touching any migration, service, or model that handles financial data. The constitutional rules are non-negotiable and enforced at both the application and database level.

## Pull Request Checklist

Every PR that modifies `app/Services/`, `database/migrations/`, or any model with `ImmutableLedger` must include the constitutional self-review checklist (see `.github/PULL_REQUEST_TEMPLATE.md`).

Changes to CONSTITUTION.md require:
1. A PR description explaining why the change is needed
2. A 72-hour cooling-off period before merge
3. Explicit founder sign-off
4. Note: Articles I–X are non-amendable

## Code Review Standards

- All accounting service changes require a second reviewer familiar with the ImmutableLedger pattern
- Migrations that install DB triggers require pre-validation blocks (see CONSTITUTION.md §4 Rule 2)
- No migration may use `DISABLE TRIGGER` (see CONSTITUTION.md Article IX.B)
