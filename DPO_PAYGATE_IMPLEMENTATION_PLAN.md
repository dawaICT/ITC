# DPO Pay / PayGate Online Payment — Implementation Plan

**Scope:** Add hosted-checkout online payment in two workflows — (1) course registration
("Pay & Submit Registration") and (2) the student fees module ("Pay Fees Online") — with
verify-before-post, idempotent ledger updates, and receipts.

---

## 0. Reality check — what already exists (do NOT rebuild)

The codebase already contains a working DPO hosted-checkout integration for the **fees**
leg. The plan reuses it rather than duplicating it:

| Piece | File | State |
|---|---|---|
| Gateway client (createToken / verifyToken / payment URL) | `includes/DpoGateway.php` | Built |
| Config, invoice, tx-log, apply-payment helpers | `includes/payment_helpers.php` | Built |
| Fees payment start (CSRF, ownership, server-side amount) | `students/process_dpo_payment.php` | Built |
| Return + XML IPN callback (verify-first, idempotent-ish) | `students/dpo_callback.php` | Built |
| Payment page UI with DPO card | `students/payment.php` | Built |
| Registration writer | `students/processCourseReg.php` (UI: `courseReg.php`) | Built, no payment leg |
| Fee obligation logic | `students/includes/FeeGuard.php` (`fg_check_fee_threshold`, 50%), `InvoiceService.php`, `fee_structure` / `program_fees` tables | Built |

**Why it doesn't work today (verified against the live DB 2026-07-03):**

1. **`payment_gateway_transactions` does not exist.** `payment_ensure_gateway_transactions_table()`
   runs `CREATE TABLE IF NOT EXISTS` at runtime, but the app connects as DML-only
   `wucportal_app`, so the DDL silently fails and every DPO start attempt dies.
   (Known repo rule: DDL must run as `wucportal_migrator` via a migration script.)
2. **No `dpo_*` settings rows** exist in `portal_settings` (only `enforce_ca_payment`,
   `enforce_exam_payment`), and there is **no admin UI** to configure them —
   `payment_dpo_is_ready()` is therefore always false.
3. **`invoices` has no `amount_paid`/`balance` columns** (only `amount`, `status`,
   `payment_status`), so `payment_apply_completed_payment()` rejects every **partial**
   payment ("cannot track partial payments").
4. `students/fees.php` never links to the DPO flow; only `payment.php` does.

**Product naming decision (flag to stakeholder):** the existing client targets
**DPO Pay (API 3G v6, `secure.3gdirectpay.com`)** — DPO's hosted checkout used in Zambia
(ZMW). "PayGate PayWeb3" (`secure.paygate.co.za`, PAYGATE_ID + checksum) is a *different*
DPO-owned product, South-Africa-centric. Unless ITC's merchant account is literally a
PayGate SA account, **keep the DPO Pay client**. If it turns out to be PayWeb3, add a
sibling `includes/PaygateWeb3Gateway.php` implementing the same
`createToken/verifyToken/paymentUrl` interface and select the provider from settings —
nothing else in this plan changes.

**Schema trap:** `student_payments` already exists with its own shape
(`payment_id` PK, `Sid`, `amount_paid`, `payment_status` enum pending/completed/failed,
`status` enum approved/cancelled/reversed, `reference_number`, `receipt_number` UNIQUE,
`student_fee_account_id`, …). It is the **ledger** consumed by feesControl pages.
Do **not** recreate it per the original prompt's field list — keep it as the ledger and
put gateway-specific fields (`payment_type`, `registration_id`, `pay_request_id`,
`result_code`, `raw_response`, …) on `payment_gateway_transactions` instead.

---

## 1. Architecture

**One principle everywhere:** *the portal creates the obligation → the gateway confirms
payment → only a server-to-server verify (`verifyToken`) posts money and unlocks state.*

```
Course registration                      Fees module
───────────────────                      ───────────
courseReg.php (select courses)           fees.php / payment.php (pick invoice)
        │                                        │
processCourseReg.php                             │
  courses saved status='pending_payment'         │
  is_active=0 + invoice ensured                  │
        │                                        │
        └────────► students/payments/paygate_start.php ◄────────┘
                     type=course_registration | fee_payment
                     amount computed SERVER-SIDE
                     row in payment_gateway_transactions (status=pending)
                     DpoGateway::createToken → redirect to hosted checkout
                                   │
                   students/payments/paygate_return.php
                     (browser redirect AND XML IPN — both paths identical)
                     DpoGateway::verifyToken  ← the ONLY trusted signal
                        │
            ┌───────────┴──────────────┐
        Result 000                  declined/cancelled
            │                           │
  payment_apply_completed_payment   tx status=failed/cancelled
  (ledger + invoice, idempotent)    registration stays pending_payment
            │                       student may retry (new reference)
  if type=course_registration:
    activate course_registration rows
    (status='registered', is_active=1)
  receipt number issued → printReceipt.php
```

**Registration state machine** (`course_registration.status`, existing varchar(20)):
`pending_payment` → `registered` (on verified payment, `is_active=1`)
`pending_payment` rows are excluded from myCourses/exams/e-learning queries via
`is_active=0`, which those queries already filter on.

---

## 2. Phase 1 — Foundation repair (migration + config)

### 2.1 Migration script `migrations/2026_07_dpo_paygate.php` (run as `wucportal_migrator`)

1. **Create `payment_gateway_transactions`** — use the DDL already embedded in
   `payment_ensure_gateway_transactions_table()` (payment_helpers.php:302) **plus** new
   columns:

   ```sql
   payment_type      VARCHAR(32) NOT NULL DEFAULT 'fee_payment',
                     -- course_registration | fee_payment | exam_fee | other
   semester_registration_id INT NULL,       -- registration leg linkage
   program_code      VARCHAR(20) NULL,
   course_codes      TEXT NULL,             -- JSON list for course_registration
   result_code       VARCHAR(16) NULL,      -- DPO Result (000 = approved)
   result_desc       VARCHAR(255) NULL,     -- ResultExplanation
   receipt_no        VARCHAR(50) NULL,
   KEY idx_pgt_type (payment_type),
   KEY idx_pgt_semreg (semester_registration_id)
   ```

   (`reference` unique, `pay_request_id`≈`provider_token` unique, `raw_response`≈
   `response_payload`, timestamps — all already in the existing DDL.)

2. **`ALTER TABLE invoices`** `ADD amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
   ADD balance DECIMAL(10,2) NULL` + backfill `balance = amount` for unpaid rows.
   This unblocks partial payments in `payment_apply_completed_payment()`.

3. **Seed `portal_settings`** keys (INSERT IGNORE): `dpo_enabled` (0), `dpo_company_token`,
   `dpo_service_type`, `dpo_api_url`, `dpo_payment_url`, `dpo_currency` (ZMW),
   `dpo_ptl_hours` (24), `dpo_debug_mode`, `dpo_default_payment`,
   plus registration-policy keys: `reg_payment_gate_enabled` (1),
   `reg_payment_threshold_pct` (50).

### 2.2 Code fixes in `includes/payment_helpers.php`

- Replace the runtime `@$db->query(CREATE TABLE …)` in
  `payment_ensure_gateway_transactions_table()` with a `wuc_table_exists()` check that
  logs + returns false when missing (per the repo's no-runtime-DDL rule); callers surface
  "Online payment is not available yet" instead of crashing.
- Extend `payment_create_gateway_transaction()` / `payment_update_gateway_transaction()`
  allow-lists with the new columns.
- New helpers:
  - `payment_required_for_registration($db, $sid, $year, $semester, $academicYear): array`
    → `{required, tuition_total, paid, threshold_pct}` — reuses the same fee_structure /
    student_fee_accounts sources as `fg_check_fee_threshold()` so the gate and the checkout
    amount can never disagree. Required = `max(0, tuition*threshold% − verified_paid)`.
  - `payment_issue_receipt_number($db): string` — matches existing
    `student_payments.receipt_number` format; store on both ledger row and gateway tx.
- Have `payment_apply_completed_payment()` return the receipt number.

### 2.3 Admin configuration page `admin/payment_gateway_settings.php`

Form (existing admin chrome + CSRF) writing the `dpo_*` and `reg_payment_*` keys to
`portal_settings`; masked company-token field; "Test connection" button that calls
`createToken` for K1 in test mode and reports the result. Guard: admin login +
finance/admin role check.

---

## 3. Phase 2 — Fees module leg (mostly wiring)

### 3.1 New shared endpoints under `students/payments/`

Move/generalize rather than fork — old URLs kept as thin redirects for bookmarks:

- **`students/payments/paygate_start.php`** — generalization of
  `process_dpo_payment.php`. POST + CSRF + student guard. Branches on
  `payment_type`:
  - `fee_payment`: invoice must belong to `$_SESSION['Sid']`
    (`payment_fetch_invoice($db, $ref, $sid)` already enforces this); amount =
    posted amount clamped to `payment_invoice_outstanding()`, **never trusted raw**;
    reject ≤ 0.
  - `course_registration`: ignores any posted amount entirely; recomputes via
    `payment_required_for_registration()`; stores `semester_registration_id`,
    `program_code`, `course_codes` on the tx row.
  - Common: unique reference `payment_generate_reference('DPO')`, tx row
    `status='pending'`, `createToken`, persist `provider_token` + request/response
    payloads, redirect to hosted checkout. On gateway failure → friendly retry screen.
- **`students/payments/paygate_return.php`** — generalization of `dpo_callback.php`
  (keeps both the browser-redirect and XML-IPN entry paths). After `verifyToken`
  returns `Result 000`:
  1. Persist `result_code`/`result_desc`/`raw` on the tx (all results, not just success).
  2. Idempotency gate: skip if tx already `completed` (existing check) **and** the
     duplicate-reference check inside `payment_apply_completed_payment()`.
  3. Post ledger via `payment_apply_completed_payment()` (writes `payments` +
     `student_payments` + invoice inside one DB transaction with `FOR UPDATE` lock).
  4. `payment_type === 'course_registration'` → activate registration (§4.3).
  5. Stamp `receipt_no`, redirect to a result page with a "View receipt" link
     (`printReceipt.php`).
  - Non-000: mark tx `failed`/`cancelled`, touch **nothing else**, show retry UI.

### 3.2 `students/fees.php` — add the entry point

"Pay Fees Online" button per outstanding invoice (and on the balance summary card),
linking to `payment.php?invoice=…` where the DPO card already lives. Show
`payment_status`/`payment_message` flash from the return redirect. Hide the button when
`!payment_dpo_is_ready()` (keep bank-transfer path). UI follows existing Bootstrap 5
card patterns — no redesign.

### 3.3 `students/payments/index.php` — "My Online Payments"

Student-scoped list of their `payment_gateway_transactions` (date, type, invoice/term,
amount, status badge, receipt link, "Retry" for failed/cancelled). Nav entry under Fees.

---

## 4. Phase 3 — Course registration leg (the genuinely new part)

### 4.1 `students/courseReg.php` (UI)

- Above the submit button, show a fee panel: tuition for the term, verified amount paid,
  required-now amount (from `payment_required_for_registration()`).
- If required > 0 and gateway ready → button reads **"Pay & Submit Registration"**
  (posts `pay_online=1`). If required = 0 → normal "Submit Registration".
  If gateway not configured → current behaviour (FeeGuard blocks with the bank-transfer
  message) is unchanged.

### 4.2 `students/processCourseReg.php` (writer) — minimal diff

Current line ~134 hard-blocks on `fg_check_fee_threshold()`. Change to:

```
if (!$fgRes['ok']) {
    if ($payOnline && payment_dpo_is_ready($config) && reg_payment_gate_enabled) {
        insert course rows with status='pending_payment', is_active=0
        (same prepared-insert machinery, transaction, legacy mirror skipped until активation)
        ensure invoice exists (InvoiceService) for the term
        redirect → students/payments/paygate_start.php
                   (type=course_registration, semester/year/semRegId context, CSRF)
    }
    throw current exception;   // unchanged fallback
}
```

- The existing duplicate-term guard must treat `pending_payment` rows as *resumable*,
  not as duplicates: same student+term+pending → reuse those rows and go straight to
  payment (retry path) instead of erroring.
- Sponsored/TEVETA/CDF students already pass `fg_check_fee_threshold()` → they never
  see the payment branch (preserved behaviour).

### 4.3 Activation on verified payment (in `paygate_return.php`)

Inside the same flow that posts the ledger, after `payment_apply_completed_payment()`
succeeds:

```sql
UPDATE course_registration
   SET status='registered', is_active=1, amount_paid = amount_paid + ?, updated_at=NOW()
 WHERE Sid=? AND semester=? AND Year=? AND status='pending_payment'
```

(using the tx row's stored context; column names via the existing SHOW COLUMNS discovery
pattern — remember `Sid`, not `student_id`). Then run the canonical-sync + legacy
`student_courses` mirror steps that `processCourseReg.php` performs for active rows.
Re-check `fg_check_fee_threshold()` after posting; if the verified payment still doesn't
meet the threshold (edge: fee structure changed mid-flight), leave rows pending and tell
the student the residual amount.

### 4.4 Expiry hygiene

`pending_payment` rows older than N days (setting, default 7): shown on the student's
registration page with "Complete payment" / "Cancel"; a cleanup in the existing daily
maintenance path (or on-page lazy cleanup) deactivates stale ones. No cron dependency
required for correctness — activation only ever happens through verify.

---

## 5. Phase 4 — Accounts visibility

- **`accounts/payments_report.php`** — staff report over `payment_gateway_transactions`:
  filters (date range, type, status, provider), totals row, drill-down to raw
  request/response payloads, CSV export via the existing `trx_*` report engine pattern.
  Guard: accounts/finance role.
- **`accounts/student_ledger.php`** exists as `accounts/fees_student_payments.php` /
  `fees_statement.php` — add a "Channel: DPO Pay" badge + gateway-reference column where
  the ledger rows came from an online payment (join on `reference_number`).
- Flag mismatches: verified gateway tx with no matching ledger row (reconciliation list).

---

## 6. Security checklist (mapped to existing enforcement)

| Rule | Where |
|---|---|
| Prepared statements everywhere | already the pattern in all touched helpers |
| CSRF on start | `process_dpo_payment.php` pattern, reuse in `paygate_start.php` |
| Never trust redirect; verify server-to-server | `verifyToken` before any state change (`dpo_callback.php` pattern) |
| Server-side amounts | fee leg: clamp to invoice outstanding; registration leg: recompute, ignore POST |
| Ownership | `payment_fetch_invoice(…, $sid)`; registration context from `$_SESSION['Sid']` only |
| Idempotent posting | unique `reference_number` + unique `provider_token` + duplicate-ref check + `FOR UPDATE` invoice lock (all existing) |
| No card data stored | hosted checkout only; store token/refs/result only |
| Log all gateway traffic | `request_payload`/`response_payload` columns + tx status audit; add `wuc_audit_log` entries (`action`, `details`, `created_at` — real columns) for start/verify/activate |
| Registration stays pending until confirmed | state machine §1; `is_active=0` keeps pending rows invisible to downstream modules |
| No runtime DDL | §2.2 — table existence check + migration as `wucportal_migrator` |

---

## 7. Delivery order & test plan

1. **Phase 1** (migration, settings, admin page) — after this the *existing* fees flow
   becomes functional in DPO test mode. Verify: `payment_gateway_transactions` exists;
   `payment.php` shows the DPO card enabled.
2. **Phase 2** (fees leg wiring + history page). Tests with seeded WUC900–WUC910 accounts:
   full payment, partial payment (needs the new invoice columns), cancel at gateway,
   duplicate return-hit (replay `paygate_return.php` URL — balance must not double-post),
   IPN XML post, tampered POST amount (must clamp), foreign invoice ref (must 404).
3. **Phase 3** (registration leg). Tests: register-with-payment happy path → courses
   active + receipt; declined payment → still pending, retry works, duplicate-term guard
   doesn't fire on retry; sponsored student bypass; threshold=0 term (fails open, no
   payment step); replay of return URL doesn't re-activate or double-post.
4. **Phase 4** (accounts report + reconciliation).
5. Manual smoke via `wucportal-php` preview server with minted sessions
   (nav chrome caveat under PHP CLI noted in memory).

**Open questions for stakeholders (non-blocking, defaults chosen):**
- Is the merchant account DPO Pay (assumed, ZMW) or PayGate PayWeb3 (then add the sibling
  gateway class, §0)?
- Registration required-payment policy: keep the existing 50% threshold (default) or
  full-fee/deposit per program? (`reg_payment_threshold_pct` setting makes this a config
  change, not a code change.)
- Should accounts be able to set per-student "approved partial payment" amounts for the
  fees leg, or is "any amount ≤ outstanding" acceptable (current default)?
