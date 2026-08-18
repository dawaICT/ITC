# Finance & Accounting Module (WUC Portal)

## Setup
- Import SQL: `db/finance_module.sql` (idempotent)
- Optionally run: `db/normalize_schema.sql` and `db/finance_tables.sql`
- Seed cost centers (programs, library, e-learning, labs):
  - POST `admin/ajax/finance_seed_cost_centers.php`

## Admin UI
- `admin/finance.php` (Budgeting, Fees, AR/AP, Reporting)
- Sidebar link already added under Departments → Finance

## AJAX Endpoints
- Cost centers: `admin/ajax/finance_get_cost_centers.php`
- Budgets list: `admin/ajax/finance_get_budgets.php`
- Save budget: `admin/ajax/finance_save_budget.php` (POST: period_year, period_term, cost_center_id, allocated_amount)
- Programs: `admin/ajax/finance_get_programs.php`
- Save installment plan: `admin/ajax/finance_save_plan.php` (POST: program_code, plan_name, num_installments, schedule_json)
- Auto-invoice new registrations: `admin/ajax/finance_autoinvoice.php`
- AR reminders queue: `admin/ajax/finance_trigger_reminders.php`
- Apply late fees: `admin/ajax/finance_apply_late_fees.php`
- Reports: `admin/ajax/finance_report.php?type=program_profitability|fee_analytics`
- Export CSV: `admin/ajax/finance_export.php?type=budgets|ar`
- Block exam access: `admin/ajax/finance_block_exam_access.php` (POST: student_id)
- Exchange rate API (internal): `admin/ajax/finance_api_exchange_rate.php?base=ZMW&quote=USD`

## Student UI
- `students/fees.php` (installments + payments overview)
- `students/payment.php?invoice=...` (invoice checkout with DPO Pay + bank transfer proof upload)

## Online Payments
- DPO hosted checkout starts from `students/process_dpo_payment.php`
- DPO return/webhook handler: `students/dpo_callback.php`
- Bank transfer proofs are queued in `payment_gateway_transactions`
- Finance review queue: `accounts/pendingPayments.php`
- Counter/admin posting pages now allocate directly to invoices:
  - `accounts/payments.php`
  - `admin/payments.php`
- Finance setup page surfaces integration status in `admin/finance/fees.php`

## DPO Settings
Store these in `portal_settings` (or set matching values before writing them there):
- `dpo_enabled` = `1`
- `dpo_company_token`
- `dpo_service_type`
- `dpo_api_url`
- `dpo_payment_url`
- `dpo_currency`
- `dpo_redirect_url`
- `dpo_back_url`
- `dpo_callback_url`
- Optional: `dpo_default_payment`, `dpo_default_payment_country`, `dpo_default_payment_mno`, `dpo_ptl_hours`, `dpo_debug_mode`

## Bank Details Settings
Optional `portal_settings` keys used by the student bank-transfer screen:
- `bank_name`
- `bank_branch`
- `bank_branch_code`
- `bank_account_name`
- `bank_account_number`
- `bank_swift_code`
- `bank_proof_max_mb`

## Roles & Access
- Enforced via `includes/finance_helpers.php` reading `access_right.assigned_access`
- Allowed roles per endpoint listed in files

## Multi-currency
- `finance_exchange_rates` table; set rates manually or via external job; helper converts amounts

## Audit
- `audit_log` used via `log_audit()` in helpers

## Integrations
- Auto-invoice ties to `student_program`, `invoices`, `fee_structure`
- Exam block writes to `exam_blocks` (created if missing)

## Notes
- SMS sending is queued in `finance_ar_reminders`; connect actual SMS gateway to dispatch queued messages.
- PDF reports use TCPDF if available, fallback to CSV.


