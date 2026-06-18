# Mobile AI Brief — How EMI works in the web POS, and the backend API status

This explains exactly how JewelFlow's **web** POS handles EMI (installment) sales so
you can mirror the model in the mobile app, and lists which backend API endpoints
exist today vs. what still needs to be built. Read it end-to-end before building any
mobile EMI screen.

---

## 1. The mental model (how web does it)

EMI in JewelFlow is a **two-step flow**, not a one-shot sale:

```
STEP 1 (at POS):  cart + customer  ──►  create a DRAFT invoice   (no money, no stock change yet)
STEP 2 (EMI form): pick down payment + #EMIs + interest  ──►  FINALIZE the draft into an EMI plan
```

Why two steps: at POS the cashier just signals "this is an EMI sale". The real EMI
terms (down payment, number of EMIs, interest, which account the down payment came
through) are captured on a dedicated EMI screen, and only then is the invoice
**finalized** and the **InstallmentPlan** created.

### Key rules / invariants (apply these on mobile too)
- **Items stay `in_stock` during the draft.** They are NOT reserved. (An older
  "reserve" attempt set status=`reserved`, which isn't a valid item status and 500'd —
  it was removed. Do not reintroduce reservation.) The finalize step is the single
  place that moves items `in_stock → sold`.
- **The draft is a real `invoices` row with `status='draft'`.** It carries the line
  items but no payments/cash/ledger entries yet.
- **Finalize requires the items to still be `in_stock`** (re-checked under a row lock).
  If something sold them meanwhile, finalize fails cleanly with "no longer available".
- **The invoice is determined, not chosen.** Once POS creates the draft, the EMI form
  is locked to *that* invoice + *that* customer — the cashier cannot pick a different
  draft (that previously caused finalizing against the wrong invoice). Mobile must do
  the same: carry the `invoice_id` from step 1 into step 2; never show a free invoice
  picker for a POS-originated EMI.
- **Cancelling the EMI form discards the draft** (marks the draft invoice `cancelled`),
  it does not leave it lying around. Items stay `in_stock`. Invoices are immutable and
  are never hard-deleted — "discard" = status → `cancelled`.

---

## 2. Down payment & EMI payments link to a specific account (like POS)

When the down payment (or any later monthly EMI payment) is taken by **UPI / Bank /
Wallet**, the cashier picks the *specific configured account* — not just "UPI". These
accounts are `ShopPaymentMethod` rows (the same ones POS uses), each with a `type`
(`upi`/`bank`/`wallet`) and an `account_label` (e.g. "Shop GPay", "HDFC Current").

Rules:
- Method `cash` / `card` / `other` → **no** account id.
- Method `upi` / `bank` / `wallet` → **must** pick an account of the **matching type**;
  the backend rejects a mismatched or another-shop's or inactive account.
- The chosen account id is stored as `payment_method_id` on the payment row, and is
  shown back in payment history (e.g. "Upi · Shop GPay").

Mobile should offer the same account picker (filtered to the chosen method's type),
exactly as the mobile POS payment screen already does for normal sales.

---

## 3. The web request/response shape (what to mirror)

### Step 1 — POS sell with EMI
Web POS `POST /pos/sell` (mobile equivalent: `POST /api/mobile/pos/sell`) is told it's
an EMI sale by a payment line with **`mode: 'emi'`**:

```jsonc
{
  "customer_id": 3,
  "item_ids": [42],
  "discount": 0,
  "round_off": 0,
  "payments": [ { "mode": "emi", "amount": 0 } ]   // the 'emi' line is the EMI signal
}
```

The backend strips the `emi` line, creates the **draft invoice**, and the **mobile**
endpoint (`POST /api/mobile/pos/sell`) now returns a clean, app-native payload (no web
URL):

```json
{
  "emi_draft": true,
  "invoice_id": 6,
  "finalize_endpoint": "/api/mobile/v1/installments/finalize",
  "discard_endpoint": "/api/mobile/v1/installments/discard-draft",
  "message": "EMI draft created. Finalize it to create the plan, or discard if cancelled."
}
```

Carry `invoice_id` into the native EMI screen and call the finalize endpoint (§5).
(The **web** POS returns a `redirect_url` instead — that's for the browser only; ignore
it on mobile.)

### Step 2 — Finalize the draft into a plan (web)
Web `POST /installments` with:

```jsonc
{
  "customer_id": 3,
  "invoice_id": 6,                 // the draft from step 1 — carried over, not chosen
  "from_pos_emi": 1,
  "down_payment": 5000,
  "total_emis": 6,                 // 2..24
  "interest_rate_annual": 3,       // 0..60, flat: principal × rate × months/12
  "down_payment_method": "upi",    // cash | upi | bank | wallet | other
  "down_payment_method_id": 12     // required when method is upi/bank/wallet (a ShopPaymentMethod of that type)
}
```

On success the draft is finalized (invoice → finalized, items → sold), an
`InstallmentPlan` is created, and the down payment is recorded (linked to the account).

### Recording a later monthly EMI payment (web)
Web `POST /installments/{plan}/pay`:

```jsonc
{
  "amount": 5000,
  "payment_method": "upi",         // cash | upi | card | bank_transfer
  "payment_method_id": 12,         // required for upi / bank_transfer (matching ShopPaymentMethod)
  "notes": "optional"
}
```

Server caps the amount at the remaining balance and auto-completes the plan when the
last EMI is paid.

---

## 4. Backend API endpoints that EXIST for mobile today

Two route families: **legacy** `/api/mobile/...` (`routes/mobile.php`) and **canonical
v1** `/api/mobile/v1/...` (`routes/mobile_v1.php`, wrapped in the `{data, meta, errors}`
envelope + `X-Idempotency-Key` on mutations). All are `auth:sanctum` + tenant/
subscription/account/shop guarded.

Relevant to sales/payments:
| Method | Path | Purpose |
|---|---|---|
| POST | `/api/mobile/pos/bootstrap` (GET) | POS bootstrap data |
| POST | `/api/mobile/pos/preview` / `/quote` | price preview / quote |
| POST | `/api/mobile/pos/sell` | the sale — `mode:'emi'` creates a draft and returns the EMI-draft payload above |
| GET | `/api/mobile/invoices`, `/invoices/{id}` | list / show invoices |
| POST | `/api/mobile/invoices/{invoice}/payments` | record a payment against an invoice |
| GET/POST | `/api/mobile/v1/cashbook` (+ `/drawer-check`) | cash book |
| POST | `/api/mobile/repairs` (+ `/deliver`, `/status`) | repairs |
| POST | `/api/mobile/quick-bills` (+ `/void`) | quick bills |

## 5. EMI / Installment endpoints — NOW BUILT ✅

These mobile **v1** endpoints now exist (mounted at `/api/mobile/v1/...`, wrapped in the
`{data, meta, errors}` envelope, `auth:sanctum` + tenant/subscription/account/shop
guards). Mutations require **`X-Idempotency-Key`** (8–80 chars) and `can:sales.create`;
reads require `can:sales.view`. They reuse the web `InstallmentService` — identical
accounting. All response bodies below are the contents of the envelope's `data`.

### POST `/api/mobile/v1/installments/finalize`  — finalize a POS-EMI draft → plan
Request:
```jsonc
{
  "invoice_id": 6,                 // the draft from /pos/sell (required)
  "down_payment": 5000,            // required, ≥ 0, must be < invoice total
  "total_emis": 6,                 // required, 2..24
  "interest_rate_annual": 3,       // optional, 0..60 (flat: principal × rate × months/12)
  "down_payment_method": "upi",    // cash | upi | bank | wallet | other (default cash)
  "down_payment_method_id": 12,    // REQUIRED when method is upi/bank/wallet AND down_payment > 0
  "down_payment_reference": "TXN1" // optional
}
```
`201` → the full plan object (see the shared shape at the end of §5). `422` if the
invoice isn't a draft / already has a plan / has no customer, or the account is
missing/wrong-type/inactive.

### POST `/api/mobile/v1/installments/{plan}/pay`  — record a monthly EMI payment
Request:
```jsonc
{
  "amount": 5000,                  // required, ≥ 1 (server caps at remaining balance)
  "payment_method": "upi",         // cash | upi | card | bank_transfer (required)
  "payment_method_id": 12,         // REQUIRED for upi (→upi account) / bank_transfer (→bank account)
  "notes": "optional"
}
```
`201` → the full plan object (with the new payment in `payments[]`). The plan
auto-completes when the last EMI is paid. `422` if the plan isn't active, amount
exceeds the balance, or the account is invalid.

### POST `/api/mobile/v1/installments/discard-draft`  — discard an abandoned draft
Request: `{ "invoice_id": 6 }` → `200` `{ "discarded": true, "invoice_id": 6 }`. Marks
the draft invoice `cancelled`; items stay `in_stock`. `422` if it isn't a discardable
draft (e.g. already finalized).

### GET `/api/mobile/v1/installments`  — list plans
Optional `?status=active|completed|defaulted` and `?per_page` (≤50). Returns
`{ plans: [ <summary> ], pagination: { next_cursor, prev_cursor, page_size, has_more } }`
(cursor pagination). Each summary: `id, status, customer{id,name,mobile},
invoice_number, emi_amount, total_payable, remaining_amount, emis_paid, total_emis,
next_due_date`.

### GET `/api/mobile/v1/installments/{plan}`  — one plan with payment history
Returns the full plan object.

**Full plan object** (returned by finalize / pay / show) = the summary fields plus:
`down_payment, principal_amount, interest_rate_annual, interest_amount, total_amount,
total_paid, outstanding, is_overdue`, and
`payments: [{ id, amount, payment_date, payment_method, account: {id,label}|null, notes }]`.

### Account list for the picker
Reuse the POS bootstrap (`GET /api/mobile/pos/bootstrap`) `paymentMethods` — active
`ShopPaymentMethod`s, each with `type` (upi/bank/wallet) and `account_label`. Filter to
the chosen method's type for the picker; cash/card carry no account.

---

For reference, the web logic these mirror lives in `app/Services/InstallmentService.php`
(`finalizeDraftInvoiceToPlan`, `recordPayment`, `discardDraftPosEmiInvoice`) and
`app/Http/Controllers/InstallmentController.php`. New mobile endpoints should call the
SAME service methods (do not re-implement the accounting) and return the new v1
envelope, with `X-Idempotency-Key` on the mutations.

---

## 6. Recommended mobile flow (endpoints are now live — §5)

1. POS screen → user marks the sale as EMI → `POST /api/mobile/pos/sell` with a
   `mode:'emi'` payment → keep the returned `invoice_id` (ignore `redirect_url`).
2. Navigate to a **native EMI screen** pre-loaded with that invoice (locked — no
   invoice picker), showing the draft total.
3. Capture down payment + #EMIs (2–24) + interest (0–60) + down-payment method, and
   when method is upi/bank/wallet, an account picker (matching type).
4. Submit to the finalize endpoint (POST /api/mobile/v1/installments/finalize). On success → show the plan; later EMIs are
   recorded via POST /api/mobile/v1/installments/{plan}/pay.
5. If the user cancels → call POST /api/mobile/v1/installments/discard-draft so the draft doesn't linger.

Keep the invariants from §1 (items stay in_stock until finalize; invoice is
determined, not chosen; cancel discards the draft).
