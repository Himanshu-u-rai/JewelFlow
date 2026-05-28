## Summary

<!-- Describe what this PR changes and why -->

## Constitutional Self-Review

For PRs touching `app/Services/`, `database/migrations/`, models with `ImmutableLedger`, or `CONSTITUTION.md`:

- [ ] I have read the relevant sections of [CONSTITUTION.md](../CONSTITUTION.md)
- [ ] No `forceFill()` is called on a finalized record at update time
- [ ] All new migrations that touch finalized-record tables include a pre-validation block
- [ ] No migration uses `DISABLE TRIGGER` or `SET session_replication_role = replica`
- [ ] No Article IX constitutional trigger has been dropped, renamed, or disabled
- [ ] Any new GST rate computation goes through `GstRateResolver` (not hardcoded)
- [ ] Reconciliation commands added or modified remain read-only (exit 1, no DB writes)

## Test Plan

<!-- How was this tested? -->

## Notes for Reviewer

<!-- Anything specific the reviewer should check -->
