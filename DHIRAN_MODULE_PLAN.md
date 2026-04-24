# Dhiran (Gold Loan / Girvi) Module — Complete Implementation Plan

> **Version:** 3.0
> **Date:** April 9, 2026
> **Status:** Approved for implementation
> **Author:** Himanshu Rai + Claude (AI pair programming)

---

## 1. What is Dhiran?

Dhiran (also called Girvi) is a **loan-against-gold-collateral system** — a core revenue stream for Indian jewelry shops. A customer pledges gold/silver jewelry as security and receives a cash loan. The jeweler holds the gold until the loan is repaid with interest. This module manages the full lifecycle of such loans.

## 2. Architecture Decision

### Fully Isolated Module
The Dhiran module is a **completely self-contained system** inside JewelFlow SaaS. It does NOT interfere with any existing JewelFlow modules (POS, invoices, inventory, schemes, repairs, etc.).

```
JewelFlow SaaS
├── POS, Inventory, Invoices, Schemes, Repairs... (existing — UNTOUCHED)
└── Dhiran Module (fully isolated)
       └── Only reads: Customer model (from JewelFlow)
       └── Everything else: own tables, own service, own views, own cash ledger
```

**Why isolated?**
- Dhiran has its own cash flow (disbursements & collections) — must NOT mix with JewelFlow's cash book
- Pledged items are NOT inventory — they should never appear in POS, catalog, or stock reports
- Shop owner wants separate reporting for the lending business
- If Dhiran has a bug, it cannot break POS/billing

**Only shared dependency:** `Customer` model (read-only — borrower is an existing JewelFlow customer)

## 3. Research & Competitive Analysis

### Competitor Products Studied:
- **SAER Girvi Software** — KYC with photo/ID, branded receipts, SMS/WhatsApp alerts, overdue notifications
- **Gehna ERP Girvi** — Simple + compound interest, loss identification for unprofitable loans
- **Bravo PawnMaster** — Forfeiture-to-inventory pipeline, renewal workflows, partial payments, 36+ reports
- **WinGold** — Period extension, pre-payment, partial payment, auction management
- **GirviApp** — Mobile-first, pledge ticket generation, locker tracking

### RBI Regulations (Effective April 2026):
- **LTV Ratio:** 85% for loans <₹2.5L, 80% for ₹2.5-5L, 75% for >₹5L
- **KYC:** Mandatory (Aadhaar, PAN)
- **Forfeiture:** 30-day public notice required before auction
- **Item Return:** Within 7 working days of loan closure
- **Transparency:** All interest rates and charges must be disclosed upfront
- **Documentation:** Stamped pledge receipts mandatory

---

## 4. All Use Cases & Scenarios

### Loan Lifecycle
1. **Normal flow:** Pledge → Interest payments → Full repayment → Closure
2. **Interest-only payments:** Customer pays only interest for months, principal untouched
3. **Partial principal repayment:** Customer pays some principal over time
4. **Partial item release:** Multiple items pledged, release one by paying proportional principal
5. **Renewal/extension:** Pay accumulated interest, reset tenure, keep collateral
6. **Multiple renewals:** Same loan renewed many times (tracked via chain)
7. **Full closure with item return:** Pay all → items released → closure certificate printed
8. **Pre-closure / Early closure:** Close before maturity — minimum interest period enforced
9. **Default → Forfeiture:** Overdue beyond grace → 30-day RBI notice → forfeiture executed

### Financial Scenarios
10. **Processing fee:** One-time fee at loan creation (flat amount or % of principal)
11. **Penalty / Late fee:** Extra charge when overdue (configurable % per month after grace period)
12. **Minimum interest period:** Even if closed in 5 days, minimum 1 month interest charged
13. **Interest type flexibility:** Flat monthly (default), daily, or compound — configurable per shop
14. **Payment priority:** Any payment covers penalty first → interest second → principal last
15. **Overpayment prevention:** Cannot pay more than outstanding balance

### Collateral Scenarios
16. **Multiple items per loan:** Chain + bangles + ring — each tracked individually
17. **Mixed metals:** Gold + silver items in same loan — each valued at own rate
18. **Item photo documentation:** Photos stored for each pledged item
19. **Item condition at release:** Condition note + confirmation required
20. **HUID tracking:** Hallmark ID recorded per item

### Business Rules
21. **LTV ratio enforcement:** Principal cannot exceed collateral value × LTV ratio
22. **Tiered LTV (RBI):** Different LTV for loans <₹2.5L vs ≥₹2.5L
23. **Min/max loan amount:** Configurable per shop
24. **Locked period:** Minimum loan tenure before pre-closure allowed
25. **Multiple loans per customer:** Simultaneous active loans supported
26. **KYC compliance:** Aadhaar, PAN, photo — mandatory or optional per shop

### Documentation & Receipts
27. **Pledge receipt:** Branded, printable with all item details, terms, signature lines
28. **Payment receipt:** For each interest/principal payment
29. **Closure certificate:** Proof that loan is fully closed and items returned
30. **Forfeiture notice:** Printable RBI-compliant 30-day warning

### Reporting
31. **Active loans report:** All active loans with outstanding amounts
32. **Overdue report:** Loans past maturity with days overdue, risk flags
33. **Interest income report:** Interest + penalty collected, date-range filterable
34. **Forfeiture report:** Forfeiture history
35. **Dhiran cash book:** All disbursements & collections (own ledger, separate from JewelFlow)
36. **Customer loan history:** All loans for a customer across all statuses
37. **Profitability report:** Flag loans where interest collected < cost of capital

---

## 5. Database Schema (6 new tables, ZERO modifications to existing tables)

### Migration 1: `2026_04_10_100000_create_dhiran_module_tables.php`

### Table: `dhiran_loans`

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId | constrained, cascadeOnDelete |
| loan_number | string(30) | unique per shop, e.g. DH-000001 |
| customer_id | foreignId | constrained (FK to customers) — **only JewelFlow dependency** |
| loan_date | date | Date of pledge |
| gold_rate_on_date | decimal(12,4) | Gold rate entered at loan time |
| silver_rate_on_date | decimal(12,4) nullable | Silver rate if silver items included |
| principal_amount | decimal(14,2) | Cash disbursed |
| processing_fee | decimal(14,2) default(0) | One-time fee at creation |
| processing_fee_type | string(10) default('flat') | 'flat' or 'percent' |
| interest_rate_monthly | decimal(5,2) | Monthly rate, e.g. 1.50 |
| interest_type | string(10) default('flat') | 'flat', 'daily', 'compound' |
| penalty_rate_monthly | decimal(5,2) default(0) | Extra % per month when overdue |
| ltv_ratio_applied | decimal(5,2) | LTV at time of loan |
| total_collateral_value | decimal(14,2) | Market value of pledged items |
| total_fine_weight | decimal(10,6) | Sum fine weight all items |
| outstanding_principal | decimal(14,2) | Decreases with repayments |
| outstanding_interest | decimal(14,2) default(0) | Accrued unpaid interest |
| outstanding_penalty | decimal(14,2) default(0) | Accrued unpaid penalty |
| interest_accrued_through | date nullable | Last date interest was calculated |
| total_interest_collected | decimal(14,2) default(0) | Running total |
| total_penalty_collected | decimal(14,2) default(0) | Running total |
| total_principal_collected | decimal(14,2) default(0) | Running total |
| tenure_months | integer | Original tenure |
| maturity_date | date | loan_date + tenure_months |
| min_lock_months | integer default(0) | Minimum period before pre-closure (0 = no lock) |
| grace_period_days | integer default(30) | Days after maturity before default |
| min_interest_months | integer default(1) | Minimum months interest charged even on early closure |
| status | string(20) default('active') | active, renewed, closed, defaulted, forfeited |
| renewed_count | integer default(0) | Times renewed |
| renewed_from_id | foreignId nullable | Self-ref for renewals |
| kyc_aadhaar | string(12) nullable | |
| kyc_pan | string(10) nullable | |
| kyc_photo_path | string(500) nullable | Customer photo at pledge |
| terms_text | text nullable | Loan terms snapshot |
| notes | text nullable | |
| closed_at | datetime nullable | |
| closure_notes | text nullable | |
| forfeited_at | datetime nullable | |
| forfeiture_notice_sent_at | datetime nullable | |
| forfeiture_notice_text | text nullable | |
| created_by | foreignId nullable | |
| timestamps | | |

**Indexes:** `unique(shop_id, loan_number)`, `index(shop_id, status)`, `index(shop_id, customer_id)`, `index(shop_id, status, maturity_date)`

---

### Table: `dhiran_loan_items`

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId | constrained |
| dhiran_loan_id | foreignId | constrained |
| description | string(500) | "22K Gold Chain, Temple design" |
| category | string(100) nullable | Ring, Chain, Bangle, Necklace, etc. |
| metal_type | string(20) default('gold') | gold, silver |
| quantity | integer default(1) | Number of pieces |
| gross_weight | decimal(10,6) | Grams |
| stone_weight | decimal(10,6) default(0) | |
| net_metal_weight | decimal(10,6) | After stone deduction |
| purity | decimal(5,2) | e.g. 22.00 for 22K |
| fine_weight | decimal(10,6) | net_weight × purity / 24 |
| rate_per_gram_at_pledge | decimal(12,4) | Frozen rate at pledge |
| market_value | decimal(14,2) | fine_weight × rate |
| loan_value | decimal(14,2) | market_value × LTV |
| photo_path | string(500) nullable | Item photo |
| huid | string(30) nullable | Hallmark ID |
| status | string(20) default('pledged') | pledged, released, forfeited |
| released_at | datetime nullable | |
| release_condition_note | text nullable | Staff confirms condition |
| released_by | foreignId nullable | Staff who released |
| forfeited_at | datetime nullable | |
| timestamps | | |

**No FK to JewelFlow items table** — Dhiran items are completely independent.

---

### Table: `dhiran_payments` (immutable)

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId | |
| dhiran_loan_id | foreignId | |
| payment_date | date | |
| type | string(30) | disbursement, processing_fee, interest_payment, penalty_payment, principal_repayment, renewal_interest, pre_closure, forfeiture_adjustment |
| amount | decimal(14,2) | Always positive |
| direction | string(3) | 'in' or 'out' |
| payment_method | string(30) default('cash') | cash, upi, bank |
| interest_component | decimal(14,2) default(0) | |
| penalty_component | decimal(14,2) default(0) | |
| principal_component | decimal(14,2) default(0) | |
| processing_fee_component | decimal(14,2) default(0) | |
| outstanding_principal_after | decimal(14,2) | Snapshot |
| outstanding_interest_after | decimal(14,2) | Snapshot |
| outstanding_penalty_after | decimal(14,2) default(0) | Snapshot |
| receipt_number | string(50) nullable | |
| notes | text nullable | |
| created_by | foreignId nullable | |
| timestamps | | |

---

### Table: `dhiran_cash_entries` (immutable — Dhiran's OWN cash ledger)

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId | constrained |
| dhiran_loan_id | foreignId | constrained |
| dhiran_payment_id | foreignId nullable | |
| entry_date | date | |
| type | string(3) | 'in' or 'out' |
| amount | decimal(14,2) | |
| source_type | string(30) | disbursement, processing_fee, interest_collection, penalty_collection, principal_collection, pre_closure, forfeiture |
| payment_method | string(30) default('cash') | cash, upi, bank |
| description | text nullable | |
| created_by | foreignId nullable | |
| timestamps | | |

**Indexes:** `index(shop_id, entry_date)`, `index(shop_id, type)`

---

### Table: `dhiran_ledger_entries` (immutable)

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId | |
| dhiran_loan_id | foreignId | |
| dhiran_payment_id | foreignId nullable | |
| entry_type | string(30) | disbursement, processing_fee, interest_accrual, interest_collection, penalty_accrual, penalty_collection, principal_repayment, item_release, renewal, pre_closure, forfeiture, closure |
| direction | string(6) | debit, credit |
| amount | decimal(14,2) | |
| balance_after | decimal(14,2) | Running principal balance |
| interest_balance_after | decimal(14,2) default(0) | |
| penalty_balance_after | decimal(14,2) default(0) | |
| note | text nullable | |
| meta | jsonb nullable | |
| created_by | foreignId nullable | |
| timestamps | | |

---

### Table: `dhiran_settings` (one per shop)

| Column | Type | Notes |
|---|---|---|
| id | bigIncrements | PK |
| shop_id | foreignId unique | |
| is_enabled | boolean default(false) | Module activation toggle |
| default_interest_rate_monthly | decimal(5,2) default(2.00) | % per month |
| default_interest_type | string(10) default('flat') | 'flat', 'daily', 'compound' |
| default_penalty_rate_monthly | decimal(5,2) default(0.50) | Overdue penalty % |
| default_ltv_ratio | decimal(5,2) default(75.00) | |
| high_value_ltv_ratio | decimal(5,2) default(75.00) | For loans >= threshold |
| high_value_threshold | decimal(14,2) default(250000.00) | RBI ₹2.5L threshold |
| default_tenure_months | integer default(12) | |
| default_min_lock_months | integer default(0) | Lock-in period |
| default_min_interest_months | integer default(1) | Min interest even on early close |
| min_loan_amount | decimal(14,2) default(1000.00) | |
| max_loan_amount | decimal(14,2) default(5000000.00) | |
| processing_fee_type | string(10) default('flat') | 'flat' or 'percent' |
| processing_fee_value | decimal(10,2) default(0) | |
| grace_period_days | integer default(30) | |
| forfeiture_notice_days | integer default(30) | RBI: 30 days |
| loan_number_prefix | string(10) default('DH-') | |
| kyc_mandatory | boolean default(true) | |
| receipt_header_text | text nullable | |
| receipt_footer_text | text nullable | |
| receipt_terms_text | text nullable | |
| closure_certificate_text | text nullable | |
| sms_reminders_enabled | boolean default(false) | |
| reminder_days_before_due | integer default(7) | |
| timestamps | | |

### Migration 2: `2026_04_10_100001_add_dhiran_permissions.php`

Permissions: `dhiran.view`, `dhiran.create`, `dhiran.pay`, `dhiran.release`, `dhiran.renew`, `dhiran.forfeit`, `dhiran.settings`, `dhiran.reports` — group `dhiran`.

---

## 6. Models

All in `app/Models/Dhiran/` subdirectory.

| Model | Traits | Immutable? |
|---|---|---|
| DhiranLoan | BelongsToShop | No (status changes) |
| DhiranLoanItem | BelongsToShop | No (release/forfeit) |
| DhiranPayment | BelongsToShop, ImmutableLedger | Yes |
| DhiranCashEntry | BelongsToShop, ImmutableLedger | Yes |
| DhiranLedgerEntry | BelongsToShop, ImmutableLedger | Yes |
| DhiranSettings | BelongsToShop | No |

### Key Relationships
- `DhiranLoan` → belongsTo(Customer), hasMany(Items, Payments, CashEntries, LedgerEntries)
- `DhiranLoan.renewedFrom()` → belongsTo(self), `renewals()` → hasMany(self)
- Immutable models use `static record()` pattern (like SchemePayment, SchemeLedgerEntry)

### DhiranLoan Computed Properties
- `isOverdue()`, `daysOverdue()`, `daysTillMaturity()`
- `isInLockPeriod()` — loan_date + min_lock_months > today
- `minimumInterestAmount()` — principal × rate × min_interest_months
- `canPreClose()` — not in lock period + active status

---

## 7. Service Layer — `DhiranService`

File: `app/Services/DhiranService.php`

### All Methods:

| Method | Purpose |
|---|---|
| `createLoan(Customer, items[], params[])` | Validate, calculate, disburse, record everything |
| `accrueInterest(DhiranLoan)` | Flat/daily/compound interest + penalty if overdue |
| `recordInterestPayment(loan, amount, method)` | Penalty first → interest second |
| `recordRepayment(loan, amount, method)` | Penalty → interest → principal split |
| `releaseItem(loan, item, payment, method, conditionNote)` | Proportional principal, mark released |
| `preCloseLoan(loan, method)` | Lock period check + minimum interest enforcement |
| `renewLoan(loan, ?tenure, ?rate)` | Clear interest, create new loan, transfer items |
| `closeLoan(loan)` | Validate zero balance, release all items |
| `sendForfeitureNotice(loan)` | RBI notice, set timestamp |
| `executeForfeit(loan)` | Validate notice period, write off, forfeit items |
| `loanSummary(loan)` | Full computed snapshot |
| `customerLoanHistory(customer, ?status)` | All loans for customer |
| `accrueInterestBatch(shopId)` | Daily cron — all active loans |
| `getOverdueLoans()` | Active + past maturity |
| `getDefaultRiskLoans()` | Past maturity + grace |
| `getUnprofitableLoans()` | Interest collected < expected |

### Interest Calculation Logic:

**Flat monthly** (most common in jewelry shops):
```
monthly = principal_amount × rate / 100   (always on ORIGINAL principal, not outstanding)
daily_accrual = monthly / 30 × days_since_last_accrual
```

**Daily:**
```
daily = principal_amount × (rate / 30) / 100 × days
```

**Compound** (monthly compounding):
```
base = outstanding_principal + outstanding_interest
monthly = base × rate / 100
```

**Penalty** (after maturity + grace period):
```
penalty = outstanding_principal × penalty_rate / 100 / 30 × overdue_days
```

### Payment Priority Order:
All payments split in this order: **Penalty → Interest → Principal**

---

## 8. Controller & Routes

File: `app/Http/Controllers/DhiranController.php`

| Method | Route | HTTP | Permission |
|---|---|---|---|
| dashboard | `/dhiran` | GET | dhiran.view |
| activate | `/dhiran/activate` | POST | dhiran.settings |
| create | `/dhiran/create` | GET | dhiran.create |
| store | `/dhiran` | POST | dhiran.create |
| loans | `/dhiran/loans` | GET | dhiran.view |
| show | `/dhiran/loans/{loan}` | GET | dhiran.view |
| payInterest | `/dhiran/loans/{loan}/pay-interest` | POST | dhiran.pay |
| repay | `/dhiran/loans/{loan}/repay` | POST | dhiran.pay |
| releaseItem | `/dhiran/loans/{loan}/release-item` | POST | dhiran.release |
| preClose | `/dhiran/loans/{loan}/pre-close` | POST | dhiran.pay |
| renew | `/dhiran/loans/{loan}/renew` | POST | dhiran.renew |
| close | `/dhiran/loans/{loan}/close` | POST | dhiran.pay |
| sendNotice | `/dhiran/loans/{loan}/send-notice` | POST | dhiran.forfeit |
| forfeit | `/dhiran/loans/{loan}/forfeit` | POST | dhiran.forfeit |
| receipt | `/dhiran/loans/{loan}/receipt` | GET | dhiran.view |
| closureCertificate | `/dhiran/loans/{loan}/closure-certificate` | GET | dhiran.view |
| forfeitureNotice | `/dhiran/loans/{loan}/forfeiture-notice` | GET | dhiran.view |
| paymentReceipt | `/dhiran/loans/{loan}/payments/{payment}/receipt` | GET | dhiran.view |
| customerLoans | `/dhiran/customers/{customer}` | GET | dhiran.view |
| settings | `/dhiran/settings` | GET | dhiran.settings |
| updateSettings | `/dhiran/settings` | PATCH | dhiran.settings |
| reportActive | `/dhiran/reports/active` | GET | dhiran.reports |
| reportOverdue | `/dhiran/reports/overdue` | GET | dhiran.reports |
| reportInterest | `/dhiran/reports/interest` | GET | dhiran.reports |
| reportForfeiture | `/dhiran/reports/forfeiture` | GET | dhiran.reports |
| reportCashbook | `/dhiran/reports/cashbook` | GET | dhiran.reports |
| reportProfitability | `/dhiran/reports/profitability` | GET | dhiran.reports |

---

## 9. Blade Views (17 files)

All in `resources/views/dhiran/` — dark theme matching JewelFlow.

| View | Purpose |
|---|---|
| `activation.blade.php` | Module intro + "Enable Dhiran" button |
| `dashboard.blade.php` | Stats, activity feed, quick actions, maturity alerts |
| `loans/index.blade.php` | Loan list with status tabs, search, sort |
| `loans/create.blade.php` | Multi-step: customer → rates → items (auto-calc) → terms → KYC → confirm |
| `loans/show.blade.php` | Loan detail, items, payments, ledger, actions, pre-close calculator |
| `loans/receipt.blade.php` | Printable pledge receipt |
| `loans/payment-receipt.blade.php` | Printable payment receipt |
| `loans/closure-certificate.blade.php` | Printable closure proof |
| `loans/forfeiture-notice.blade.php` | Printable RBI 30-day notice |
| `customers/loans.blade.php` | Customer's full loan history |
| `settings.blade.php` | All settings |
| `reports/active.blade.php` | Active loans report |
| `reports/overdue.blade.php` | Overdue loans, risk flags |
| `reports/interest.blade.php` | Interest + penalty income |
| `reports/forfeiture.blade.php` | Forfeiture history |
| `reports/cashbook.blade.php` | Dhiran's own cash book |
| `reports/profitability.blade.php` | Profit/loss per loan |

---

## 10. Navigation & Module Activation

### Sidebar section (gated by `dhiran.view` permission):
```
DHIRAN (Gold Loans)
├── Dashboard        → /dhiran
├── New Loan         → /dhiran/create
├── All Loans        → /dhiran/loans
├── Reports          → /dhiran/reports/active
└── Settings         → /dhiran/settings
```

### Activation Flow:
1. First visit to `/dhiran` → activation page explaining the module
2. Owner clicks "Enable Dhiran" → `dhiran_settings.is_enabled = true`
3. Subsequent visits → full dashboard

---

## 11. Scheduled Commands

| Command | Schedule | Purpose |
|---|---|---|
| `DhiranAccrueInterest` | Daily | Interest + penalty accrual on all active loans |
| `DhiranOverdueAlert` | Daily | Flag overdue loans, send reminders |
| `DhiranMaturityAlert` | Daily | Alert for loans maturing in next 7 days |

---

## 12. Files to Create/Modify

### New Files (29):
```
database/migrations/2026_04_10_100000_create_dhiran_module_tables.php
database/migrations/2026_04_10_100001_add_dhiran_permissions.php
app/Models/Dhiran/DhiranLoan.php
app/Models/Dhiran/DhiranLoanItem.php
app/Models/Dhiran/DhiranPayment.php
app/Models/Dhiran/DhiranCashEntry.php
app/Models/Dhiran/DhiranLedgerEntry.php
app/Models/Dhiran/DhiranSettings.php
app/Services/DhiranService.php
app/Http/Controllers/DhiranController.php
resources/views/dhiran/activation.blade.php
resources/views/dhiran/dashboard.blade.php
resources/views/dhiran/loans/index.blade.php
resources/views/dhiran/loans/create.blade.php
resources/views/dhiran/loans/show.blade.php
resources/views/dhiran/loans/receipt.blade.php
resources/views/dhiran/loans/payment-receipt.blade.php
resources/views/dhiran/loans/closure-certificate.blade.php
resources/views/dhiran/loans/forfeiture-notice.blade.php
resources/views/dhiran/customers/loans.blade.php
resources/views/dhiran/settings.blade.php
resources/views/dhiran/reports/active.blade.php
resources/views/dhiran/reports/overdue.blade.php
resources/views/dhiran/reports/interest.blade.php
resources/views/dhiran/reports/forfeiture.blade.php
resources/views/dhiran/reports/cashbook.blade.php
resources/views/dhiran/reports/profitability.blade.php
app/Console/Commands/DhiranAccrueInterest.php
app/Console/Commands/DhiranOverdueAlert.php
app/Console/Commands/DhiranMaturityAlert.php
```

### Modified Files (4 — minimal touch):
```
app/Services/BusinessIdentifierService.php    — add KEY_DHIRAN + nextDhiranIdentifier()
app/Models/Customer.php                        — add dhiranLoans() relationship
resources/views/layouts/app.blade.php          — add Dhiran sidebar section
routes/web.php                                 — add Dhiran route group
```

---

## 13. Implementation Order

1. Migrations (6 tables + permissions)
2. Models (6 new in `app/Models/Dhiran/`)
3. BusinessIdentifierService update
4. Customer model update
5. DhiranService (core logic)
6. DhiranController
7. Routes
8. Views: activation → dashboard → create → index → show → receipts → settings → reports
9. Sidebar navigation
10. Scheduled commands

---

## 14. Future Enhancements (Not in v1)

- **Top-up loan:** Additional cash on same collateral when gold price rises
- **Additional collateral:** Add items to existing loan
- **Under-collateralized alerts:** When gold price drops below loan value
- **Auction process:** Full RBI-compliant auction workflow
- **Bulk interest collection:** Pay interest for multiple loans at once
- **SMS/WhatsApp integration:** Automated reminders (flag exists, implementation later)
- **Mobile API:** Dhiran endpoints for React Native app
