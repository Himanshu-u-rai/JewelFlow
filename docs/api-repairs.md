# Repairs — Mobile API Reference

Base URL: `/api/mobile`  
Auth: `Authorization: Bearer <sanctum_token>` on every request.  
All endpoints are scoped to the authenticated user's shop automatically.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/repairs` | List repairs with filters |
| GET | `/repairs/{id}` | Full repair detail |
| POST | `/repairs` | Create / receive a new repair |
| POST | `/repairs/{id}/status` | Update status (received → in_repair → ready) |
| POST | `/repairs/{id}/deliver` | Mark delivered and generate invoice |

---

## Status lifecycle

```
received → in_repair → ready → (deliver) → delivered
```

- `/status` handles the first three transitions.
- `/deliver` is the only way to reach `delivered` — it creates an invoice and records payment.
- A `delivered` repair is immutable. No status can move it backwards.

---

## GET /repairs

Returns a paginated list of repairs for the shop.

**Query parameters**

| Param | Type | Example | Notes |
|-------|------|---------|-------|
| `status` | string | `in_repair` | Filter by status: `received`, `in_repair`, `ready`, `delivered` |
| `overdue` | boolean | `1` | Only repairs past their `due_date` that are not yet delivered |
| `per_page` | integer | `20` | Items per page, default 20 |

**Response 200**

```json
{
  "current_page": 1,
  "data": [ ...RepairObject ],
  "last_page": 2,
  "per_page": 20,
  "total": 34
}
```

---

## GET /repairs/{id}

Returns a single repair with customer details.

**Response 200** — see [Repair Object](#repair-object).

**Response 404** — repair not found or belongs to another shop.

---

## POST /repairs

Receives a new item for repair. Status is always set to `received`.

**Request body**

| Field | Required | Type | Rules |
|-------|----------|------|-------|
| `customer_id` | Yes | integer | Must belong to this shop |
| `item_description` | Yes | string | max 255 — e.g. "Gold ring", "Silver chain" |
| `description` | No | string | max 1000 — details about the repair work needed |
| `gross_weight` | Yes | number | grams, min 0.001 |
| `purity` | Yes | number | karat, 1–24 |
| `estimated_cost` | Yes | number | min 0 |
| `due_date` | No | date | Expected return date |
| `image_base64` | No | string | Base64-encoded JPEG/PNG/WebP, max 5 MB |

**Response 201**

```json
{
  "id": 14,
  "repair_number": 14,
  "status": "received",
  "image": "repairs/3/01JDXXX.jpg",
  "image_path": "repairs/3/01JDXXX.jpg",
  "image_url": "https://example.com/storage/repairs/3/01JDXXX.jpg",
  "message": "Repair created successfully."
}
```

**Response 422** — validation errors.

---

## POST /repairs/{id}/status

Moves a repair between `received`, `in_repair`, and `ready`.  
To move to `delivered`, use `/repairs/{id}/deliver` instead.

**Request body**

| Field | Required | Type | Rules |
|-------|----------|------|-------|
| `status` | Yes | string | `received`, `in_repair`, or `ready` |
| `due_date` | No | date | Update or clear the expected return date |

**Example — mark in repair**

```json
{
  "status": "in_repair"
}
```

**Example — mark ready for pickup**

```json
{
  "status": "ready",
  "due_date": "2026-05-10"
}
```

**Response 200**

```json
{
  "id": 14,
  "repair_number": 14,
  "status": "in_repair",
  "final_cost": null,
  "due_date": null,
  "message": "Repair status updated."
}
```

**Response 404** — repair not found or belongs to another shop.  
**Response 422** — invalid status value, or attempt to change a delivered repair.

---

## POST /repairs/{id}/deliver

Marks the repair delivered, generates a finalized invoice, and records payment. This is the only path to `delivered` status.

**Request body**

| Field | Required | Type | Rules |
|-------|----------|------|-------|
| `amount` | Yes | number | Final repair charge before GST, min 0 |
| `include_gst` | No | boolean | Default `false` — set `true` to add GST on top |
| `gst_rate` | No | number | 0–100, used only when `include_gst=true`. Defaults to the shop's configured GST rate |
| `payment_mode` | No | string | `cash` (default), `upi`, `bank`, `wallet`, `other` |

**Example — cash delivery, no GST**

```json
{
  "amount": 850.00,
  "include_gst": false,
  "payment_mode": "cash"
}
```

**Example — UPI, GST inclusive**

```json
{
  "amount": 1200.00,
  "include_gst": true,
  "gst_rate": 3,
  "payment_mode": "upi"
}
```

**Response 200**

```json
{
  "id": 14,
  "repair_number": 14,
  "status": "delivered",
  "final_cost": 1236.00,
  "invoice_id": 88,
  "invoice_number": "INV-0088",
  "payment_mode": "upi",
  "gst_rate": 3.0,
  "gst": 36.00,
  "total": 1236.00,
  "message": "Repair delivered and billed successfully."
}
```

**Response 404** — repair not found or belongs to another shop.  
**Response 422** — repair already delivered, or invalid amount.

---

## Repair Object

Returned by `GET /repairs` (list items) and `GET /repairs/{id}`.

```json
{
  "id": 14,
  "shop_id": 3,
  "customer_id": 7,
  "repair_number": 14,
  "item_description": "Gold ring — prong broken",
  "description": "Two prongs need re-tipping, clean and polish",
  "status": "in_repair",
  "gross_weight": "5.320000",
  "purity": "22.00",
  "estimated_cost": "850.00",
  "final_cost": null,
  "due_date": "2026-05-08",
  "invoice_id": null,
  "image": "repairs/3/01JDXXX.jpg",
  "image_path": "repairs/3/01JDXXX.jpg",
  "image_url": "https://example.com/storage/repairs/3/01JDXXX.jpg",
  "created_at": "2026-05-01T09:15:00.000000Z",
  "updated_at": "2026-05-01T11:30:00.000000Z",
  "customer": {
    "id": 7,
    "first_name": "Ramesh",
    "last_name": "Shah",
    "mobile": "9876543210"
  }
}
```

**Field notes**

| Field | Notes |
|-------|-------|
| `repair_number` | Sequential integer per shop, used for display (e.g. "REP-014") |
| `purity` | Karat value as decimal string — `"22.00"` means 22K |
| `gross_weight` | Grams, 6 decimal places |
| `estimated_cost` | Set at intake, shown to customer as an estimate |
| `final_cost` | Only populated after `deliver` — the actual billed amount including GST |
| `due_date` | Nullable — expected return date, can be updated via `/status` |
| `invoice_id` | Nullable until delivered — links to the generated invoice |
| `image_url` | Absolute URL ready for `<img>` or download. `null` if no photo |
| `customer` | Eager-loaded on list and detail — `null` if customer was deleted |

---

## Status reference

| Status | Meaning | Who sets it |
|--------|---------|-------------|
| `received` | Item received at shop, not yet started | Created automatically on `POST /repairs` |
| `in_repair` | Work in progress | `POST /repairs/{id}/status` |
| `ready` | Repair done, waiting for customer pickup | `POST /repairs/{id}/status` |
| `delivered` | Handed back to customer, invoice generated | `POST /repairs/{id}/deliver` only |

---

## Error responses

**401** — missing or invalid token.  
**404** — repair not found or belongs to another shop.  
**422** — validation failed. Body:

```json
{
  "message": "The status field is required.",
  "errors": {
    "status": ["The status field is required."]
  }
}
```

---

## Common mistakes

1. **Sending `status: delivered` to `/status`** — will fail with a 422. Use `/deliver` for that transition; it requires `amount` and creates the invoice.
2. **Calling `/deliver` on an already-delivered repair** — will fail with a 422 "Repair is already delivered."
3. **Omitting `amount` on `/deliver`** — required field, will fail with a 422.
4. **`include_gst: true` without `gst_rate`** — server falls back to the shop's configured rate. Send `gst_rate` explicitly if the rate differs per job.
5. **Trying to move status backwards** (e.g. `delivered → ready`) — blocked with a 422.
