# SCAN SESSION WORKFLOW AUDIT
*Date: 2026-06-10 — Read-only diagnosis. No code changed. No architecture proposed.*
*Scope: the mobile-phone → web-POS barcode bridge ("Scan with phone" / POS Connect).*

---

## Verdict (read this first)

| Question | Answer |
|---|---|
| Is the observed failure a **bug**? | **No.** The pipeline behaved exactly as written. |
| Is it a **missing safeguard**? | **Yes — primary finding.** No visibility/feedback when the desktop isn't listening, and the 1-phone-per-session lifecycle columns exist but are never enforced or populated. |
| Is it a **product-design choice**? | **Partly.** The bridge is an intentional *pull* model (phone queues, desktop polls). The gaps above are unfinished safeguards layered on that choice. |
| **Pilot blocker?** | **No.** Worst realistic outcome is operational friction + a wrong-cart that the cashier catches at the mandatory cart-review step. No data loss, no inventory loss, no silent corruption, no cross-tenant security hole. It is a **workflow edge case + visibility gap**, not a blocker for the single-counter pilot profile. |

---

## A. Current workflow — traced end to end

**Pipeline (as built):**
1. **Web POS creates session** — `ScanSessionController::create` ([app/Http/Controllers/ScanSessionController.php](app/Http/Controllers/ScanSessionController.php)) inserts `scan_sessions{shop_id, token(48), status='active', expires_at=+8h}`, audit `scan_session_created`.
2. **Mobile connects** — native app: `ScanController::connect` ([app/Http/Controllers/Api/Mobile/ScanController.php](app/Http/Controllers/Api/Mobile/ScanController.php)) matches `token + shop_id + active`, sets `mobile_connected_at` (first time only), audit `mobile_scan_connected`. (A second path exists: the public web QR page `ScanSessionController::mobile` + `postScan`.)
3. **Mobile scans** — `ScanController::send` (or `postScan`) inserts `scan_events{scan_session_id, barcode, processed=false}`, audit `mobile_scan_barcode_sent`.
4. **Web consumes** — `ScanSessionController::poll` (browser polls every 1.5s) atomically claims `scan_events WHERE scan_session_id=? AND NOT processed` under `lockForUpdate`, marks them `processed=true`, returns barcodes; the POS JS then resolves each barcode to an item and adds it to the cart. Audit `scan_events_consumed`.

**Where the real INV-like events failed — exact evidence (production data, shop_id=1):**

| Stage | Audit / data | Result |
|---|---|---|
| Session created | `scan_session_created` ×1, by **user 5 (owner)**, shop 1 | ✅ |
| Mobile connected | `mobile_scan_connected` ×26 | ✅ |
| Barcodes sent | `mobile_scan_barcode_sent` ×6, by **user 6 (staff)**, shop 1; 6 rows in `scan_events` | ✅ |
| **Web consumed** | **`scan_events_consumed` = 0; all 6 events `processed=false`** | ❌ |

**Conclusion:** the barcodes reached the backend and are sitting in the queue **unconsumed**. The break is purely that **no browser polled the session while those barcodes were queued.** Supporting detail: the 6 events are timestamped `07:09–07:10` while the surviving session row was created `12:38` — i.e. the events are **stale leftovers from an earlier morning session instance that was never polled either.** This is consistent across both: at no point did a `scan_events_consumed` row ever get written (0 lifetime).

**Explicitly ruled out by the data:**
- **Not a shop/tenant mismatch** — session, sends, and owner all `shop_id=1`.
- **Not an owner-vs-staff / permission issue** — the poll has **zero user filtering**; staff(user 6) → owner(user 5) is supported by design. Connect/send succeeded.
- **Not caused by reload** — see Section B (reload is handled via localStorage).

---

## B. Session lifecycle

| Aspect | Actual behavior | Evidence |
|---|---|---|
| **Creation** | Web action calls `scan.session.create`; one row, `status='active'`, `expires_at = now()+480min` (8h). | `ScanSessionController::create`, `SESSION_MINUTES=480` |
| **Token rotation** | A **new token is minted only when the web POS explicitly creates a session** (user action). It is **not** auto-created on page load. | `pos.blade.php` `CREATE_URL` is invoked on connect action, not on load |
| **Page reload** | **Handled — does NOT break pairing.** The token is persisted in `localStorage` (`pos_scan_state_v1_<userId>_<shopId>`); on reload `restoreScanState()` reloads the token and **resumes polling the same session.** | `pos.blade.php` `saveScanState()` / `restoreScanState()` |
| **Expiry** | 8h hard window; auto-renews silently when <30 min remain on each poll. | `poll()` → `renewIfExpiringSoon`, `RENEW_THRESHOLD_MINUTES=30` |
| **Reconnect** | Mobile can reconnect freely (26 connects recorded); `mobile_connected_at` is set first-time-only and never cleared. | `ScanController::connect` |
| **Orphaned sessions** | A session stays `active` for 8h even if the web tab is closed. The phone can keep posting into it; events accumulate `processed=false` with no reader. No automatic cleanup beyond expiry. | 6 stale unconsumed events; `status='active'` |

**Answers to the posed questions:**
- *Does a page reload intentionally create a new session?* **No.** Reload restores the existing token from `localStorage` and resumes polling. (This **corrects** an earlier verbal hypothesis that reload orphans the phone — the code does not do that.)
- *Does it silently break existing phone pairing?* **No, for reload.** Pairing survives reload. Pairing **is** silently lost only if `localStorage` is cleared, a different browser/profile is used, or the 8h session expires.
- *Is that expected or accidental?* **Expected/intentional** — localStorage persistence is deliberate code, not an accident.

**The genuine lifecycle gap:** an **orphaned-but-active** session. If the POS tab is simply *closed* (not reloaded), the session stays active 8h, the phone still reports "sent," and events pile up unread with **no signal to either side.** That is the condition that produced the 6 stuck events.

---

## C. Multi-scanner behavior — actual, verified

The schema was **designed** for 1 phone ↔ 1 session: `scan_sessions.connected_user_id`, `.device_install_id`, `.unpaired_at`, `.unpaired_reason`, `.last_seen_at`, and `scan_events.posted_by_user_id` / `.posted_by_token_id` all exist. **None of them are populated or enforced by the live code.** Verified on the real rows: every one of these columns is **NULL** across the session and all 6 events.

| Topology | What happens | What is stored | What is visible | What is audited |
|---|---|---|---|---|
| **1 phone → 1 session** | Works. Phone posts, desktop (if polling) consumes. | `scan_events` rows; `connected_user_id`/`device_install_id` **NULL** | Web shows only a single `mobile_connected` boolean (from `mobile_connected_at`); **not who, not how many** | `mobile_scan_connected`, `mobile_scan_barcode_sent` (audit row carries the sender's `user_id`) |
| **2 phones → 1 session** | **Both connect silently. Both feed the same queue → both carts merge into the one terminal.** No rejection, no take-over, no warning. | Interleaved `scan_events` on one `scan_session_id`; **no per-phone separation**, `posted_by_user_id` **NULL** | Web shows the same single "connected" boolean regardless of 1 or 2 phones — **the 2nd phone is invisible** | Two streams of `mobile_scan_barcode_sent`, each audit row tagged with its sender's `user_id` (so forensics exist in `audit_logs`, **not** in `scan_events`) |
| **3 phones → 1 session** | Same as 2 — all three merge into one cart, fully silent. | One queue, three senders, no attribution stored on the events | Still one "connected" boolean; **2 of 3 phones invisible** | Three `mobile_scan_barcode_sent` streams in `audit_logs` only |

**Key facts:**
- `ScanController::connect` checks only `token + shop_id + active`. It does **not** set `connected_user_id`/`device_install_id`, does **not** check whether another device is already paired, and does **not** unpair anyone. → unlimited silent connections.
- `ScanController::send` and `postScan` write only `scan_session_id, barcode, processed`. They leave `posted_by_user_id` **NULL**. → no on-event attribution.
- The public web path `postScan` is **unauthenticated** (token only), so it *structurally cannot* attribute a user even if asked to.
- Attribution **does** survive in `audit_logs` (the `mobile_scan_barcode_sent` row carries the sender's `user_id`), so who-scanned-what is reconstructable forensically — just not surfaced live or stored on the event.

---

## D. Real-world suitability

| Shop type | Suitability | Notes |
|---|---|---|
| **Single-counter shop** | ✅ **Fine.** | The 1:1 case is solid. Only failure mode is "phone scans while the POS tab isn't open/polling → nothing appears," resolved by training ("keep the scan panel open") and by the visibility fix in F. Matches the pilot profile. |
| **Multi-counter shop** | ⚠️ **Workable with care.** | Each terminal must run its own session/token; each phone pairs to its terminal. Risk: a phone pairing to the wrong terminal's QR, or the **same user** opening POS on two terminals (shared `localStorage` key `..._<userId>_<shopId>` → last-write-wins collision). Not a blocker; needs procedure + the visibility fix. |
| **Collaborative billing (N phones → 1 cart)** | ⚠️ **Happens silently today.** | If a shop *wants* it, it works (barely) but with no attribution surfaced and no "3 scanners connected" indicator. If a shop does *not* want it, two phones on one token merge silently → confusion. Either way it is **undisclosed behavior**, which is the real problem. |

**Does current behavior cause operational problems?** Yes, two: (1) **silent failure** when the desktop isn't polling (the observed incident), and (2) **silent merge + invisible extra scanners** in any multi-phone situation. Both are *friction/clarity* problems, not correctness problems — the cart is always reviewed by a human before the sale is finalized, so nothing is committed without a checkpoint.

---

## E. Risk assessment

Rubric: **HIGH** only for data loss / inventory loss / silent corruption / security issue.

| # | Finding | Class | Justification |
|---|---|---|---|
| 1 | Scans queue unconsumed when desktop isn't polling, with **no feedback to the phone** | **MEDIUM** | Operational friction + support calls. **No data loss** — events persist (`processed=false`); a scan never mutates inventory or creates an invoice on its own. |
| 2 | 2–3 phones merge into one cart **silently**, no take-over, no warning | **MEDIUM** | Could yield a wrong bill **only if** the cashier finalizes without reviewing the cart. The cart-review step is a mandatory human checkpoint → **not silent corruption.** |
| 3 | No live visibility of **which / how many** phones are connected | **MEDIUM** | Owner cannot see a 2nd/3rd scanner or a rogue pairing. Detection-gap, not loss. |
| 4 | `connected_user_id` / `device_install_id` / `posted_by_user_id` **exist but unused** | **LOW** | Attribution still exists in `audit_logs` (sender `user_id`), so forensics are possible. On-event attribution is a nice-to-have. |
| 5 | Orphaned active sessions linger 8h; stale events accumulate | **LOW** | Housekeeping only; bounded by expiry; no impact on correctness. |
| 6 | Token-bearer (same shop, has token + `sales.pos`) can inject barcodes into a cart | **LOW** | 48-char random + signed URL + shop-scoped + 8h expiry + permission-gated, and injected items surface in a **reviewed** cart before commit. Same-tenant only; **no cross-tenant exposure.** |
| 7 | Page reload breaks pairing | **NONE** | Disproven — `localStorage` restore resumes the session. |

**No finding qualifies as HIGH.** There is no data loss, no inventory mutation from scanning, no silent commit (cart review intervenes), and no cross-tenant or auth bypass.

---

## F. Recommendation

**Chosen option: (2) Add warning/visibility only.** (For the pilot. Revisit (3) at multi-counter scale.)

**Evidence for choosing (2):**
- The **actual incident** (Section A) was a *visibility* failure: the phone reported "sent" while the desktop wasn't reading, with no signal to anyone. Adding visibility ("desktop listening? yes/no", "last barcode received Xs ago", "N scanners connected") directly addresses the observed root cause.
- The **multi-scanner risk (E#2)** is bounded by the existing cart-review checkpoint, so full enforcement is not required for safety — it is a clarity problem, which visibility solves.
- It requires **no schema change** (the columns already exist) and **no behavior change** to the scan pipeline, so it is low-risk and fully reversible — appropriate for a pilot.

**Why not the others:**
- **(1) Leave as-is** — ignores the real, already-observed operational friction that prompted this audit. Rejected.
- **(3) Enforce 1 phone ↔ 1 session** — correct *eventual* direction for multi-counter scale (the schema was built for it), but it is a **behavior change** (silently breaks any shop currently relying on collaborative scanning) and exceeds what the single-counter pilot needs. Defer to post-pilot, gated on a real multi-counter shop onboarding.
- **(4) Support multi-scanner collaboration** — over-engineering; no shop has requested it. Premature.

**Pilot-blocker determination:** **Not a blocker.** This is a workflow edge case plus a missing-visibility safeguard. The single-counter pilot profile is unaffected in correctness; the gap is operability/clarity, addressable with visibility (option 2) and a one-line onboarding note ("keep the POS scan panel open; pair the phone *after* opening it").

---

*No code, schema, routes, or configuration were modified in producing this audit.*
