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

The backend strips the `emi` line, creates the **draft invoice**, and returns:

```json
{ "invoice_id": 6, "redirect_url": "https://.../installments/create?invoice_id=6&from_pos_emi=1" }
```

⚠️ **Mobile caveat:** the mobile POS endpoint currently returns that SAME
`redirect_url` pointing at the **web** installments page. A mobile app cannot open a
web Blade page — so today the mobile app can create the draft but has **nowhere native
to finalize it.** Mobile should use only the returned `invoice_id` and then call an
EMI-finalize endpoint (which does not exist yet — see §5).

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
| POST | `/api/mobile/pos/sell` | the sale — **accepts `mode:'emi'` and creates a draft, but returns a web redirect_url (see §3 caveat)** |
| GET | `/api/mobile/invoices`, `/invoices/{id}` | list / show invoices |
| POST | `/api/mobile/invoices/{invoice}/payments` | record a payment against an invoice |
| GET/POST | `/api/mobile/v1/cashbook` (+ `/drawer-check`) | cash book |
| POST | `/api/mobile/repairs` (+ `/deliver`, `/status`) | repairs |
| POST | `/api/mobile/quick-bills` (+ `/void`) | quick bills |

## 5. What does NOT exist yet (must be built for mobile EMI)

There are **no installment/EMI endpoints in the mobile API.** Specifically missing:
- **Finalize a POS-EMI draft into a plan** — the mobile equivalent of web
  `POST /installments` (down payment + #EMIs + interest + account). This is the #1 gap:
  mobile can create the draft but cannot complete it natively.
- **List / show EMI plans** — mobile equivalent of `GET /installments` and
  `GET /installments/{plan}`.
- **Record a monthly EMI payment** — equivalent of `POST /installments/{plan}/pay`.
- **Discard a POS-EMI draft on cancel** — equivalent of `POST /installments/discard-draft`.
- (Optional) **payment-account list** for the picker — reuse the POS bootstrap's
  payment methods (`ShopPaymentMethod` active, with `type` + `account_label`).

The web logic to mirror lives in `app/Services/InstallmentService.php`
(`finalizeDraftInvoiceToPlan`, `recordPayment`, `discardDraftPosEmiInvoice`) and
`app/Http/Controllers/InstallmentController.php`. New mobile endpoints should call the
SAME service methods (do not re-implement the accounting) and return the new v1
envelope, with `X-Idempotency-Key` on the mutations.

---

## 6. Recommended mobile flow (once the endpoints exist)

1. POS screen → user marks the sale as EMI → `POST /api/mobile/pos/sell` with a
   `mode:'emi'` payment → keep the returned `invoice_id` (ignore `redirect_url`).
2. Navigate to a **native EMI screen** pre-loaded with that invoice (locked — no
   invoice picker), showing the draft total.
3. Capture down payment + #EMIs (2–24) + interest (0–60) + down-payment method, and
   when method is upi/bank/wallet, an account picker (matching type).
4. Submit to the new finalize endpoint. On success → show the plan; later EMIs are
   recorded via the new pay endpoint.
5. If the user cancels → call the new discard endpoint so the draft doesn't linger.

Keep the invariants from §1 (items stay in_stock until finalize; invoice is
determined, not chosen; cancel discards the draft).
