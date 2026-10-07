# Diamond Bank database schema

MySQL 8.0.16+ (CHECK constraints are enforced from 8.0.16), InnoDB, `utf8mb4_unicode_ci`.

- Primary keys are `BIGINT UNSIGNED` auto-increment, named `<table>_id`. Two exceptions: `users.user_id` and `transactions.transaction_id`.
- Money columns are `DECIMAL(15,2)`, rates are `DECIMAL(5,2)`, and currency is `CHAR(3)` defaulting to `GMD`.
- Every foreign key is `ON DELETE RESTRICT`. Banking records never cascade-delete.
- Every foreign key is `ON UPDATE CASCADE`, except the four noted in the table below.

Migrations live in `database/migrations/2026_10_03_0000NN_*`. They are numbered so each table is created after the tables it references. Laravel's own `sessions`, `cache` and `jobs` migrations run first.

## Tables

| # | Table | Foreign keys | Other constraints |
|---|-------|--------------|-------------------|
| 1 | `bank` | — | `swift_code` UNIQUE |
| 2 | `branch` | `bank_id` → `bank` | `branch_code` UNIQUE |
| 3 | `employee` | — | `national_id`, `email` UNIQUE |
| 4 | `department` | — | `department_name` UNIQUE |
| 5 | `employee_branch_lnk` | `employee_id` → `employee`, `branch_id` → `branch` | |
| 6 | `employee_department_lnk` | `employee_id` → `employee`, `department_id` → `department` | |
| 7 | `customer` | `branch_id` → `branch`, `verified_by` → `employee` (NULL) | `national_id`, `email` UNIQUE |
| 8 | `customer_address` | `customer_id` → `customer` | |
| 9 | `account_type` | — | `type_name` UNIQUE |
| 10 | `account` | `customer_id` → `customer`, `branch_id` → `branch`, `account_type_id` → `account_type` | `account_number` UNIQUE, `chk_account_balance` (balance >= 0) |
| 11 | `transaction_type` | — | `type_name` UNIQUE |
| 12 | `transfer` | `from_account_id` → `account`*, `to_account_id` → `account`* | `chk_transfer_amount` (> 0), `chk_transfer_distinct_accounts` |
| 13 | `transactions` | `account_id` → `account`, `transaction_type_id` → `transaction_type`, `branch_id` → `branch` (NULL), `employee_id` → `employee` (NULL), `transfer_id` → `transfer` (NULL) | `chk_transactions_amount` (> 0), index (`account_id`, `transaction_date`) |
| 14 | `card_type` | — | `type_name` UNIQUE |
| 15 | `bank_card` | `account_id` → `account`, `card_type_id` → `card_type` | `card_number` UNIQUE |
| 16 | `loan` | `customer_id` → `customer`, `branch_id` → `branch`, `approved_by` → `employee` (NULL) | `chk_loan_amount` (> 0) |
| 17 | `loan_payment` | `loan_id` → `loan`, `branch_id` → `branch`, `transaction_id` → `transactions`* (NULL, UNIQUE), `account_id` → `account`* (NULL), `paid_by` → `users`* (NULL), `received_by` → `employee`* (NULL) | `chk_loan_payment_amount` (> 0), `chk_loan_payment_parts`, `chk_loan_payment_waiver`, `chk_loan_payment_channel`, index (`loan_id`, `payment_date`) |
| 18 | `role` | — | `role_name` UNIQUE (customer, staff, admin) |
| 19 | `users` | `role_id` → `role`, `customer_id` → `customer`* (NULL, UNIQUE), `employee_id` → `employee`* (NULL, UNIQUE) | `user_name` UNIQUE, `chk_users_one_owner` |
| 20 | `audit_log` | `user_id` → `users` (NULL) | index (`table_affected`, `record_id`) |
| 21 | `loan_type` | — | `type_name` UNIQUE, `chk_loan_type_interest_rate` (0 < rate < 100) |
| 22 | `loan_application` | `loan_id` → `loan` (PK, 1:1) | `chk_loan_application_income` (> 0) |
| 23 | `loan_guarantor` | `loan_id` → `loan` (UNIQUE: one per loan) | |
| 24 | `loan_verification` | `loan_id` → `loan`, `verified_by` → `employee` | UNIQUE (`loan_id`, `check_type`) |
| 25 | `loan_instalment` | `loan_id` → `loan`, `loan_payment_id` → `loan_payment` (NULL) | UNIQUE (`loan_id`, `instalment_number`), `chk_loan_instalment_amounts`, `chk_loan_instalment_payment` |

Tables 21–25 and the extra `loan` columns come from `2026_10_06_000001_create_loan_tables.php` (Phase F2a). It only runs while `loan` is empty. The extra `loan_payment` and `loan_instalment` payment columns come from `2026_10_08_000001_extend_loan_payment_for_repayments.php` (Phase F2b). It only runs while `loan_payment` is empty and every instalment is UNPAID.

\* These foreign keys are `ON UPDATE RESTRICT` (four from the original schema, four on `loan_payment` from Phase F2b). MySQL rejects a CHECK constraint on a column that is used by a cascading foreign key action (error 3823), and these columns appear in CHECKs. The primary keys they point to are auto-increment ids that never change, so nothing is lost.

## Columns added beyond the original ERD

| Column | Why |
|--------|-----|
| `customer.kyc_status`, `verified_by`, `verified_at` | Records who verified a customer's identity and when. `sp_open_account` refuses customers who are not `VERIFIED`. |
| `users.status` (replaces `is_active`) | PENDING / ACTIVE / BLOCKED is one auditable state. A boolean cannot tell "not yet activated" apart from "blocked". |
| `users.remember_token`, `created_at`, `updated_at` | Laravel's session guard needs these for "remember me" and model timestamps. |
| `transactions.transfer_id` | Links the TRANSFER_OUT and TRANSFER_IN rows to their `transfer`, so a transfer can be traced and reversed as one unit. |
| `users.must_change_password` (migration 22) | Makes the seeded admin replace the default password on first login. |
| `loan.loan_type_id`, `account_id`, `repayment_plan`, `monthly_instalment`, `total_interest`, `total_repayable` | The loan type (its rate is copied to `interest_rate`), the disbursement account, and the quote the customer accepted. `monthly_instalment` is NULL for a SINGLE (one payment) loan. `branch_id` is the disbursement account's branch, which decides which staff see the loan. |
| `loan.staff_approved_by`, `staff_approved_at` | Maker-checker: staff make the first approval (PENDING → AWAITING_ADMIN). `approved_by` / `approval_date` record the admin's final approval, which is also the disbursement. |
| `loan.rejected_by`, `rejected_at`, `rejection_reason`, `cancelled_at`, `disbursement_transaction_id`, `closed_at` | Who decided what and when; the LOAN_DISBURSEMENT transaction. Loan status is PENDING, AWAITING_ADMIN, REJECTED, CANCELLED, ACTIVE or CLOSED (APPROVED was dropped: approval and disbursement are one step). |
| `loan_payment.payment_type`, `channel`, `principal_paid`, `interest_charged`, `interest_waived` | What a payment was: INSTALMENT (the oldest UNPAID instalment) or EARLY_PAYOFF (everything left, with interest only for the months that have started), ONLINE or BRANCH (cash), and how much of it was principal and interest. `interest_waived` is the scheduled interest not charged on an early payoff. |
| `loan_payment.transaction_id`, `account_id`, `paid_by`, `received_by` | ONLINE: the LOAN_PAYMENT transaction, the account it came from and the customer's login. BRANCH (cash): the employee who received it, and no transaction or account, because every `transactions` row belongs to an account. `chk_loan_payment_channel` enforces this. `remaining_balance` is the sum of the loan's UNPAID instalments after the payment. |
| `loan_instalment.amount_paid`, `interest_waived` | Set when an instalment is PAID (in full) or SETTLED (by an early payoff, possibly with its interest waived); `loan_payment_id` and `paid_at` link it to the payment. |

## Stored procedures (migration 21)

- `sp_open_account(customer_id, account_type_id, branch_id, initial_deposit, currency_code, performed_by)` returns `account_id, account_number, balance`.
- `sp_transfer_funds(from_account_id, to_account_number, amount, description, channel, performed_by)` returns `transfer_id, balance_after, amount`.

- `sp_disburse_loan(loan_id, schedule_json, performed_by)` (migration `2026_10_06_000002`) returns `loan_id, transaction_id, account_number, amount, balance_after`. Admin only; the loan must be AWAITING_ADMIN and approved by a different employee. The schedule is built in PHP (`App\Support\Amortisation`) and checked row by row by the procedure before it credits the account, writes the instalments and activates the loan.

- `sp_repay_loan(loan_id, payment_type, channel, account_id, expected_instalment, expected_amount, performed_by)` (migration `2026_10_08_000002`) returns `loan_payment_id, payment_type, channel, amount, principal, interest_charged, interest_waived, transaction_id, account_number, balance_after, remaining_balance, loan_status`. ONLINE: the customer, from their own ACTIVE account (minimum balance applies). BRANCH: staff or an admin whose current branch is the loan's branch, with `account_id` NULL. It locks the loan, then the account, then the UNPAID instalments, works the amount out itself (the same rules as `App\Support\LoanRepayment`, which quotes it) and answers SQLSTATE `45001` if the caller's amount or instalment number no longer matches, or the loan is not ACTIVE. The loan becomes CLOSED when no instalment is UNPAID.

Notes:

- Call them with `DB::select('CALL ...', [...])`. Errors arrive as SQLSTATE `45000` with a message that can be shown to the end user. SQLSTATE `45001` means the record changed state before the call (for example a second loan approval); `App\Support\StoredProcedure` turns it into a 409.
- Do **not** wrap a call in `DB::transaction()`. Each procedure runs its own transaction, and `START TRANSACTION` implicitly commits whatever the caller had open.
- Amount parameters are `DECIMAL(20,6)`. With `DECIMAL(15,2)`, MySQL would round `10.555` to `10.56` before the procedure could reject it.
- Account numbers look like `DB` + branch_id padded to 3 digits + account_id padded to 7 digits. `LPAD` cuts off longer values, so this format supports branch ids up to 999 and account ids up to 9,999,999.

## Known follow-ups

- `app/Models/User.php` and `UserFactory` still describe Laravel's default `users` table (`id`, `name`, `email`). They must be updated, including `$primaryKey = 'user_id'`, before login works.
- `bank_card.card_number` stores the full card number. That is fine for this project but not PCI-DSS compliant. A real system would store a token plus the last 4 digits.
