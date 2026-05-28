# Recovery Runbooks

These runbooks describe how to diagnose and safely recover from common operational scenarios. All recovery actions use service-layer methods or artisan commands — never raw SQL on finalized records.

## Index

| # | Scenario | Key Command |
|---|---|---|
| [01](01-karigar-gram-mismatch.md) | Vault reconciliation shows grams with karigar doesn't match job order weights | `php artisan karigar:reconcile` |
| [02](02-stuck-pending-restock.md) | Items stuck in `pending_restock` for >14 days | `php artisan shop:detect-stuck` |
| [03](03-orphaned-with-karigar-item.md) | Item shows `with_karigar` but no open job order exists | `php artisan shop:detect-stuck` |
| [04](04-duplicate-karigar-receipt.md) | Karigar receipt entered twice (duplicate fine weight) | `php artisan vault:reconcile` |
| [05](05-incorrect-purity-entered.md) | Wrong purity (karats) on karigar receipt | manual calculation + vault adjustment |
| [06](06-abandoned-draft-return.md) | Draft return orders piling up from abandoned transactions | `php artisan shop:quality-signals` |
| [07](07-credit-note-refund-discrepancy.md) | Customer disputes refund amount | Returns show page → policy_breakdown |
| [08](08-returns-validate-failure.md) | `php artisan returns:validate` fails on one or more checks | `php artisan returns:validate` |

## Golden Rules

- **Diagnose first.** Always run the relevant artisan command and read the output before touching any data.
- **Append, never edit.** Corrections are compensating entries, not edits to existing rows.
- **Service layer only.** Use UI workflows and named service methods. Never raw SQL on finalized records.
- **Document everything.** Every vault adjustment must have a note. Every manual action should be traceable in the audit log.

See [docs/recovery-constitution.md](../recovery-constitution.md) for the governing principles.
