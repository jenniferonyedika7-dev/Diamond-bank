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
| 17 | `loan_payment` | `loan_id` → `loan`, `branch_id` → `branch` | `chk_loan_payment_amount` (> 0) |
| 18 | `role` | — | `role_name` UNIQUE (customer, staff, admin) |
| 19 | `users` | `role_id` → `role`, `customer_id` → `customer`* (NULL, UNIQUE), `employee_id` → `employee`* (NULL, UNIQUE) | `user_name` UNIQUE, `chk_users_one_owner` |
| 20 | `audit_log` | `user_id` → `users` (NULL) | index (`table_affected`, `record_id`) |

\* These four foreign keys are `ON UPDATE RESTRICT`. MySQL rejects a CHECK constraint on a column that is used by a cascading foreign key action (error 3823), and these columns appear in CHECKs. The primary keys they point to are auto-increment ids that never change, so nothing is lost.

## Columns added beyond the original ERD

| Column | Why |
|--------|-----|
| `customer.kyc_status`, `verified_by`, `verified_at` | Records who verified a customer's identity and when. `sp_open_account` refuses customers who are not `VERIFIED`. |
| `users.status` (replaces `is_active`) | PENDING / ACTIVE / BLOCKED is one auditable state. A boolean cannot tell "not yet activated" apart from "blocked". |
| `users.remember_token`, `created_at`, `updated_at` | Laravel's session guard needs these for "remember me" and model timestamps. |
| `transactions.transfer_id` | Links the TRANSFER_OUT and TRANSFER_IN rows to their `transfer`, so a transfer can be traced and reversed as one unit. |
| `users.must_change_password` (migration 22) | Makes the seeded admin replace the default password on first login. |

## Stored procedures (migration 21)

- `sp_open_account(customer_id, account_type_id, branch_id, initial_deposit, currency_code, performed_by)` returns `account_id, account_number, balance`.
- `sp_transfer_funds(from_account_id, to_account_number, amount, description, channel, performed_by)` returns `transfer_id, balance_after, amount`.

Notes:

- Call them with `DB::select('CALL ...', [...])`. Errors arrive as SQLSTATE `45000` with a message that can be shown to the end user.
- Do **not** wrap a call in `DB::transaction()`. Each procedure runs its own transaction, and `START TRANSACTION` implicitly commits whatever the caller had open.
- Amount parameters are `DECIMAL(20,6)`. With `DECIMAL(15,2)`, MySQL would round `10.555` to `10.56` before the procedure could reject it.
- Account numbers look like `DB` + branch_id padded to 3 digits + account_id padded to 7 digits. `LPAD` cuts off longer values, so this format supports branch ids up to 999 and account ids up to 9,999,999.

## Known follow-ups

- `app/Models/User.php` and `UserFactory` still describe Laravel's default `users` table (`id`, `name`, `email`). They must be updated, including `$primaryKey = 'user_id'`, before login works.
- `bank_card.card_number` stores the full card number. That is fine for this project but not PCI-DSS compliant. A real system would store a token plus the last 4 digits.
