# BR001 Booking Gate — Implementation Report & User Guide

**Module:** Transport / Driver-Training (Industrial Training Centre)
**Rule implemented:** BR001 — *“A student must only be booked for training after payment has been verified.”*
**Date:** 2026-06-23
**Status:** Implemented and verified (11/11 logic checks + HTTP lifecycle + live render).

---

## 1. What problem this solves

The transport module previously let staff enrol a trainee into a cohort and immediately
treat them as booked, with **no check that the fee had actually been paid and verified**.
A typed “Amount Paid” box was taken at face value. That violated the system’s single most
important rule (BR001).

This change introduces a **payment-verification workflow** and makes booking a cohort seat
**impossible until an accounts officer has verified payment**.

---

## 2. Step-by-step implementation

### Step 1 — Database schema (`migrations/2026_transport_payment_gate.sql`)
Applied as the DDL-capable `root`/migrator user (the app user is DML-only).

- Added to **`transport_enrollments`**:
  - `payment_status` — `awaiting_payment | payment_submitted | partial | verified | rejected`
  - `booking_status` — `pending_payment | booked | cancelled`
  - `payment_verified_by`, `payment_verified_at`
- Created **`transport_payments`** (one row per submitted payment): `amount`, `payment_method`,
  `bank_name`, `reference_number`, `proof_path`, `status (submitted|verified|rejected)`,
  `rejection_reason`, `submitted_by`, `verified_by`, `verified_at`, `notes`, timestamps.
- **Backfill:** existing enrolments were classified from their current figures so nothing
  in-flight broke (e.g. the seed enrolment with K100 of K500 became `partial` / `pending_payment`).

### Step 2 — Business logic (`transport/includes/transport_payment_guard.php`)
A self-contained guard library (`tpay_*` functions):

| Function | Responsibility |
|---|---|
| `tpay_submit_payment()` | Record a payment + proof; blocks duplicate references (BR010) |
| `tpay_verify_payment()` | Accounts officer confirms a payment (BR019) |
| `tpay_reject_payment()` | Accounts officer rejects with a reason |
| `tpay_recompute_enrollment()` | Recomputes `amount_paid` **from verified payments only** |
| `tpay_can_book()` | The BR001 gate — `true` only when `payment_status = verified` |
| `tpay_book_trainee()` | Books the seat; enforces capacity (BR012) |
| `tpay_*_badge()` / `tpay_booking_block_hint()` | UI labels |

**Key safety property:** `amount_paid` is *never* trusted from a form — it is always
recomputed from the sum of **verified** payments.

### Step 3 — User interface
- `transport/payments.php` — the page (wrapper that loads the Transport hub with the
  Payments section active).
- `transport/includes/payments_pane.php` — one card per enrolment: fee / verified-paid /
  balance, the payments ledger, a “Record payment / upload proof” form, verify/reject
  controls (accounts only), and the Book button (gated).
- A **“Payments & Booking”** link was added to the Transport sidebar under *Training*.

### Step 4 — Closing the bypasses (`transport/transport_management.php`)
- `add_trainee` now creates a **pending** enrolment (`amount_paid = 0`); the old “Amount Paid”
  box is informational only.
- `update_trainee` no longer writes `amount_paid`, and **refuses to set training status to
  *active*/*completed* unless the trainee is booked**.

### Step 5 — Display refinement (the part just requested)
The blocked state used to show a disabled green button plus a long sentence
(*“Payment is not verified yet — booking is blocked (BR001).”*). It now shows:

- a compact **🔒 Booking locked** indicator,
- a short, situation-specific hint (e.g. *“Waiting for accounts to verify the payment.”*),
- the full policy explanation on hover (tooltip),
- a distinct **“Ready to book”** state for accounts staff who can verify but not book.

---

## 3. The state machine

```
ENROL  ──► payment_status = awaiting_payment ,  booking_status = pending_payment   (Booking locked)
         │
 submit  ▼
         payment_submitted                                                          (Booking locked)
         │
 accounts verifies
         ├─ partial  (verified < fee)                                               (Booking locked)
         └─ verified (verified ≥ fee)            booking_status = pending_payment   (Ready to book)
                                                  │
                              training officer books▼
                                                  booked  ,  status = active         (Booked for training)
```

Rejected payments → `rejected` (submit a new one). Duplicate references are refused.

---

## 4. Who can do what (roles)

| Action | Allowed roles |
|---|---|
| View Payments & Booking, enrol, record a payment/upload proof | Anyone with Transport access (systems admin, transport HOD) |
| **Verify / reject** a payment | **Accounts officer, registrar, systems admin** (`canAccessFinance`) — BR019 |
| **Book** a verified trainee into a cohort | Transport officer / systems admin (`canAccessTransport`) |

(Separation of duties: the person who verifies money is not necessarily the person who books.)

---

## 5. How users will use it — walkthrough

### A. Training Officer — enrol the trainee
1. Transport sidebar → **Trainees**.
2. Pick the **Cohort** and **Trainee**, enter the **Fee Amount**, click **Enroll Trainee**.
3. The trainee now appears under **Payments & Booking** as *Awaiting Payment* with **Booking locked**.

### B. Record the payment (training officer or accounts)
1. Transport sidebar → **Payments & Booking**.
2. On the trainee’s card click **Record payment / upload proof**.
3. Enter the **amount**, method, bank and **reference number**, and attach the bank slip /
   mobile-money screenshot (**PDF, JPG, or PNG**). A reference number is required if no file is attached.
4. Click **Submit**. The payment shows as *Awaiting Verification* — **it does not count yet**.

### C. Accounts Officer — verify
1. Transport sidebar → **Payments & Booking** (signed in as accounts/registrar/admin).
2. Cards awaiting verification appear at the top, and the summary shows the **Awaiting verification** count.
3. In the payment row, click the green **✓** to **verify**, or the red **✗** to **reject** (a reason is required).
4. After verifying:
   - if the verified total covers the fee → **Verified · Ready to book**;
   - if not → **Partial** (booking stays locked until the balance is verified).

### D. Training Officer — book into the cohort
1. Once a card shows **Ready to book**, the green **Book into cohort** button is enabled.
2. Click it. The trainee becomes **Booked for training** (and the enrolment goes *active*).
3. If the cohort is already full, booking is refused (capacity rule, BR012).

### Things users will see
- **Booking locked** → payment not verified yet (hover for the policy reason).
- **Duplicate payment reference** → that reference was already used; use the real one.
- **“This trainee is not booked yet…”** when editing → verify payment and book first.

---

## 6. Verification performed
- `php -l` clean on all changed files.
- 11/11 automated lifecycle checks (block → submit → role-gated verify → partial →
  duplicate-reference block → full verify → book → double-book block → audit trail).
- HTTP POST lifecycle through live Apache: submit → verify → book transitioned correctly;
  a wrong CSRF token was rejected with no state change.
- Live page renders (HTTP 200, full chrome, no errors); unauthenticated access redirects to login.

## 7. How to test it yourself
1. Log into the staff portal as a **systems admin** (or use seeded `WUC901`).
2. Transport → **Trainees** → enrol a trainee with a fee (e.g. K800).
3. Transport → **Payments & Booking** → record a part payment, then verify it → see *Partial*,
   booking still locked.
4. Record + verify the balance → *Ready to book* → click **Book into cohort** → *Booked for training*.
