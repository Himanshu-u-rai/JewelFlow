# Quick Bills — Mobile API Reference

Base URL: `/api/mobile`  
Auth: `Authorization: Bearer <sanctum_token>` on every request.  
All endpoints are scoped to the authenticated user's shop automatically.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/quick-bills` | List / search bills with stats |
| GET | `/quick-bills/{id}` | Full bill detail |
| POST | `/quick-bills` | Create a new bill |
| PUT | `/quick-bills/{id}` | Update an existing bill |
| POST | `/quick-bills/{id}/void` | Void a bill |
| GET | `/quick-bills/{id}/template` | Print-ready HTML for the bill |

---

## GET /quick-bills

Returns a paginated list of bills plus shop-level stats.

**Query parameters**

| Param | Type | Example | Notes |
|-------|------|---------|-------|
| `status` | string | `issued` | Filter by status: `draft`, `issued`, `void` |
| `search` | string | `GB-0042` | Matches bill number, customer name, mobile |
| `from_date` | date | `2026-04-01` | Inclusive lower bound on `bill_date` |
| `to_date` | date | `2026-04-30` | Inclusive upper bound on `bill_date` |
| `per_page` | int | `20` | Items per page, max 100, default 20 |

**Response 200**

```json
{
  "data": [
    {
      "id": 12,
      "bill_number": "QB-0012",
      "status": "issued",
      "bill_date": "2026-04-28",
      "pricing_mode": "gst_exclusive",
      "total_amount": 45320.00,
      "paid_amount": 45320.00,
      "due_amount": 0.00,
      "customer_name": "Ramesh Shah",
      "customer_mobile": "9876543210",
      "customer": {
        "id": 7,
        "name": "Ramesh Shah",
        "mobile": "9876543210"
      },
      "created_at": "2026-04-28T10:22:00+05:30",
      "issued_at": "2026-04-28T10:22:00+05:30"
    }
  ],
  "current_page": 1,
  "last_page": 3,
  "per_page": 20,
  "total": 54,
  "stats": {
    "total_count": 54,
    "issued_count": 48,
    "draft_count": 5,
    "today_total": 128450.00,
    "outstanding_total": 12000.00
  }
}
```

---

## GET /quick-bills/{id}

Returns the complete bill with all items, payments, and totals.

**Response 200** — see [Bill Detail Object](#bill-detail-object) below.

**Response 404** — bill does not belong to this shop.

---

## POST /quick-bills

Creates a new bill. Pass `save_action: "issue"` to issue immediately, or `"draft"` to save as draft.

**Request body** — see [Bill Payload](#bill-payload) below.

**Response 201**

```json
{
  "message": "Quick bill saved successfully.",
  "quick_bill": { ...BillDetailObject }
}
```

**Response 422** — validation errors.

---

## PUT /quick-bills/{id}

Updates an existing draft or issued bill. Items and payments are fully replaced on every update (send the complete list, not a diff).

A `draft` bill can be moved to `issued` by sending `save_action: "issue"`. An already-issued bill stays issued regardless of `save_action`.

**Request body** — same as POST, see [Bill Payload](#bill-payload).

**Response 200**

```json
{
  "message": "Quick bill updated successfully.",
  "quick_bill": { ...BillDetailObject }
}
```

**Response 404** — bill does not belong to this shop.  
**Response 422** — validation errors.

---

## POST /quick-bills/{id}/void

Voids a bill. A voided bill cannot be edited or re-voided.

**Request body**

```json
{
  "void_reason": "Customer cancelled order"
}
```

| Field | Required | Rules |
|-------|----------|-------|
| `void_reason` | No | string, max 1000 chars |

**Response 200**

```json
{
  "message": "Quick bill voided successfully.",
  "quick_bill": { ...BillDetailObject }
}
```

**Response 422** — if the bill is already voided.

---

## GET /quick-bills/{id}/template

Returns the bill as rendered HTML, suitable for a WebView print/share flow.

**Response 200**

```json
{
  "id": 12,
  "bill_number": "QB-0012",
  "status": "issued",
  "bill_date": "2026-04-28",
  "html": "<!DOCTYPE html>..."
}
```

---

## Bill Payload

Fields sent when creating or updating a bill.

### Top-level fields

| Field | Required | Type | Rules |
|-------|----------|------|-------|
| `bill_date` | Yes | date | Any parseable date string |
| `pricing_mode` | Yes | string | `no_gst`, `gst_exclusive`, `gst_inclusive` |
| `gst_rate` | Yes | number | 0–100, use `3` for jewellery |
| `save_action` | No | string | `draft` (default) or `issue` |
| `customer_id` | No | integer | Must belong to this shop |
| `customer_name` | No | string | max 255 |
| `customer_mobile` | No | string | max 20 |
| `customer_address` | No | string | max 1000 |
| `discount_type` | No | string | `fixed` or `percent` |
| `discount_value` | No | number | min 0 |
| `round_off` | No | number | −9999 to +9999 |
| `notes` | No | string | max 5000 |
| `terms` | No | string | max 5000 |
| `items` | Yes | array | min 1 item |
| `payments` | No | array | omit or empty for fully-due bills |

### Item fields (`items[]`)

Each item represents one line in the bill. Items with an empty `description` are silently skipped by the server.

| Field | Required | Type | Notes |
|-------|----------|------|-------|
| `description` | Yes | string | max 255 — row is skipped if blank |
| `metal_type` | No | string | e.g. `"Gold"`, `"Silver"`, `"Diamond"` |
| `purity` | No | string | e.g. `"22K"`, `"925"`, `"18K"` |
| `hsn_code` | No | string | max 40 |
| `pcs` | No | integer | min 1, default 1 |
| `gross_weight` | No | number | grams, 3 decimal places |
| `stone_weight` | No | number | grams, 3 decimal places |
| `net_weight` | No | number | grams — if 0 or omitted, server derives `gross − stone` |
| `rate` | No | number | price per gram |
| `making_charge` | No | number | flat making amount |
| `stone_charge` | No | number | flat stone/diamond amount |
| `hallmark_charge` | No | number | hallmarking fee (new) |
| `rhodium_charge` | No | number | rhodium plating fee (new) |
| `other_charge` | No | number | any other flat charge (new) |
| `wastage_percent` | No | number | 0–1000, applied as % of metal value |
| `line_discount` | No | number | deducted from the line total |

**Line total formula (server-side):**
```
metal_value    = net_weight × rate
wastage_amount = metal_value × (wastage_percent / 100)
line_total     = metal_value + making_charge + stone_charge
               + hallmark_charge + rhodium_charge + other_charge
               + wastage_amount − line_discount
line_total     = max(0, line_total)
```

### Payment fields (`payments[]`)

Payments reduce the `due_amount`. Total paid cannot exceed the bill total.

| Field | Required | Type | Notes |
|-------|----------|------|-------|
| `payment_mode` | Yes | string | `cash`, `upi`, `bank`, `wallet`, `card`, `other` |
| `amount` | Yes | number | must be > 0 |
| `payment_method_id` | Conditional | integer | **Required** when `payment_mode` is `upi`, `bank`, or `wallet`. Get valid IDs from POS bootstrap `payment_methods`. Must not be sent for `cash`, `card`, `other`. |
| `reference_no` | No | string | max 100 — UTR, cheque number, etc. |
| `notes` | No | string | max 500 |

**Getting payment method IDs:** call `GET /api/mobile/pos/bootstrap` — the response includes a `payment_methods` object grouped as `{ upi: [...], bank: [...], wallet: [...] }`. Each entry has `id`, `name`, `type`, and `account_label`.

---

## Bill Detail Object

Returned by show, store, update, and void.

```json
{
  "id": 12,
  "bill_number": "QB-0012",
  "status": "issued",
  "bill_date": "2026-04-28",
  "pricing_mode": "gst_exclusive",
  "gst_rate": 3.00,
  "customer_id": 7,
  "customer_name": "Ramesh Shah",
  "customer_mobile": "9876543210",
  "customer_address": "12 MG Road, Surat",
  "notes": null,
  "terms": "Goods once sold will not be exchanged.",
  "void_reason": null,
  "issued_at": "2026-04-28T10:22:00+05:30",
  "voided_at": null,
  "created_at": "2026-04-28T10:22:00+05:30",
  "customer": {
    "id": 7,
    "name": "Ramesh Shah",
    "mobile": "9876543210"
  },
  "totals": {
    "subtotal": 43999.00,
    "discount_type": "fixed",
    "discount_value": 500.00,
    "discount_amount": 500.00,
    "round_off": 1.00,
    "taxable_amount": 43499.00,
    "cgst_amount": 652.49,
    "sgst_amount": 652.49,
    "igst_amount": 0.00,
    "total_amount": 44803.98,
    "paid_amount": 44803.98,
    "due_amount": 0.00
  },
  "items": [
    {
      "id": 55,
      "sort_order": 1,
      "description": "22K Gold Ring",
      "hsn_code": "7113",
      "metal_type": "Gold",
      "purity": "22K",
      "pcs": 1,
      "gross_weight": 5.320,
      "stone_weight": 0.000,
      "net_weight": 5.320,
      "rate": 7200.00,
      "making_charge": 800.00,
      "stone_charge": 0.00,
      "hallmark_charge": 45.00,
      "rhodium_charge": 0.00,
      "other_charge": 0.00,
      "wastage_percent": 2.00,
      "line_discount": 0.00,
      "line_total": 39243.00
    }
  ],
  "payments": [
    {
      "id": 22,
      "payment_mode": "upi",
      "payment_method_id": 3,
      "payment_method_label": "GPay (shop@okaxis)",
      "reference_no": "UTR123456789",
      "amount": 44803.98,
      "paid_at": "2026-04-28T10:22:00+05:30",
      "notes": null
    }
  ]
}
```

---

## GST / pricing modes

| Mode | Behaviour |
|------|-----------|
| `no_gst` | No tax calculated. `total = subtotal − discount + round_off` |
| `gst_exclusive` | GST added on top. `total = taxable + (taxable × rate%) + round_off` |
| `gst_inclusive` | GST already inside the price. `taxable = after_discount / (1 + rate/100)`, `total = after_discount + round_off` |

The server always stores CGST + SGST (intra-state split). `igst_amount` is always `0` in quick bills. If your shop uses inter-state billing, use the full Invoice module instead.

---

## Status lifecycle

```
(new) → draft → issued
          ↓        ↓
         void    void
```

- A `draft` can be edited freely or issued.
- An `issued` bill can be edited (items/payments updated) but stays `issued`.
- A `void` bill is read-only. No edits or re-void allowed.

---

## Error responses

**401** — missing or invalid token.  
**404** — bill ID not found or belongs to another shop.  
**422** — validation failed. Body:

```json
{
  "message": "The items field is required.",
  "errors": {
    "items": ["The items field is required."],
    "payments.0.payment_method_id": ["Payment method is required for mode \"upi\"."]
  }
}
```

---

## Common mistakes

1. **Sending `payment_method_id` for cash** — will fail with a 422. Only send it for `upi`, `bank`, `wallet`.
2. **Omitting `payment_method_id` for UPI/bank** — will fail with a 422. Fetch valid IDs from POS bootstrap first.
3. **Sending a partial items list on update** — items are fully replaced. Always send all rows.
4. **`customer_id` from another shop** — will fail with a 422.
5. **Editing a voided bill** — will fail with a 422 "Voided quick bills cannot be edited."
