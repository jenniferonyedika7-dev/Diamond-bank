<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * sp_disburse_loan: the admin (second) approval of a loan, which pays it out.
 * Same calling rules as 2026_10_03_000021_create_stored_procedures.php:
 * - Call with DB::select('CALL sp_disburse_loan(?, ?, ?)', [...]); the result row is the final SELECT.
 * - Do NOT call inside DB::transaction(): the procedure manages its own transaction.
 * - SQLSTATE 45000 = refused (422). SQLSTATE 45001 = the loan is no longer
 *   AWAITING_ADMIN, e.g. a second approval or the customer cancelled (409).
 *
 * The schedule is built in PHP (App\Support\Amortisation) and passed as a JSON
 * array of {instalment_number, due_date, principal, interest, amount, balance_after}.
 * The procedure never builds one; it re-checks every row against the loan
 * (amount, rate, term, plan, quoted totals) and refuses anything else:
 * - both plans: numbers 1..n, at most 2 decimals, principal > 0,
 *   amount = principal + interest, balance_after = opening − principal ending at 0,
 *   totals equal the loan's quoted total_interest (> 0) and total_repayable,
 *   due date = today (UTC) + k months;
 * - MONTHLY: n = term, k = n, interest = ROUND(opening × rate / 1200, 2),
 *   every amount except the last = loan.monthly_instalment;
 * - SINGLE: one row, k = term, interest = ROUND(P × rate × term / 1200, 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_disburse_loan');
        DB::unprepared($this->disburse());
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_disburse_loan');
    }

    private function disburse(): string
    {
        $schedule = <<<'SQL'
JSON_TABLE(p_schedule, '$[*]' COLUMNS (
        n INT PATH '$.instalment_number' NULL ON EMPTY NULL ON ERROR,
        due_date DATE PATH '$.due_date' NULL ON EMPTY NULL ON ERROR,
        principal DECIMAL(20,6) PATH '$.principal' NULL ON EMPTY NULL ON ERROR,
        interest DECIMAL(20,6) PATH '$.interest' NULL ON EMPTY NULL ON ERROR,
        amount DECIMAL(20,6) PATH '$.amount' NULL ON EMPTY NULL ON ERROR,
        balance_after DECIMAL(20,6) PATH '$.balance_after' NULL ON EMPTY NULL ON ERROR
    )) AS jt
SQL;

        return <<<SQL
CREATE PROCEDURE sp_disburse_loan(
    IN p_loan_id BIGINT UNSIGNED,
    IN p_schedule JSON,
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    DECLARE v_user_status VARCHAR(10);
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_employee_id BIGINT UNSIGNED;
    DECLARE v_type_id BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(20);
    DECLARE v_customer_id BIGINT UNSIGNED;
    DECLARE v_branch_id BIGINT UNSIGNED;
    DECLARE v_account_id BIGINT UNSIGNED;
    DECLARE v_staff_approved_by BIGINT UNSIGNED;
    DECLARE v_amount DECIMAL(15,2);
    DECLARE v_rate DECIMAL(5,2);
    DECLARE v_term SMALLINT UNSIGNED;
    DECLARE v_plan VARCHAR(10);
    DECLARE v_instalment DECIMAL(15,2);
    DECLARE v_total_interest DECIMAL(15,2);
    DECLARE v_total_repayable DECIMAL(15,2);
    DECLARE v_account_status VARCHAR(10);
    DECLARE v_account_customer_id BIGINT UNSIGNED;
    DECLARE v_account_number VARCHAR(20);
    DECLARE v_balance DECIMAL(15,2);
    DECLARE v_kyc_status VARCHAR(10);
    DECLARE v_login_status VARCHAR(10);
    DECLARE v_rows INT;
    DECLARE v_min_n INT;
    DECLARE v_max_n INT;
    DECLARE v_distinct_n INT;
    DECLARE v_sum_principal DECIMAL(24,6);
    DECLARE v_sum_interest DECIMAL(24,6);
    DECLARE v_sum_amount DECIMAL(24,6);
    DECLARE v_bad_rows INT;
    DECLARE v_balance_after DECIMAL(16,2);
    DECLARE v_transaction_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Performer: an ACTIVE admin linked to an employee record (approved_by is an employee).
    SELECT u.status, r.role_name, u.employee_id
      INTO v_user_status, v_role_name, v_employee_id
      FROM users u
      JOIN role r ON r.role_id = u.role_id
     WHERE u.user_id = p_performed_by;

    IF v_user_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The user performing this action was not found.';
    END IF;
    IF v_user_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Your user account is not active.';
    END IF;
    IF v_role_name <> 'admin' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an administrator can give the final loan approval.';
    END IF;
    IF v_employee_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Your login is not linked to an employee record.';
    END IF;

    SELECT transaction_type_id INTO v_type_id FROM transaction_type WHERE type_name = 'LOAN_DISBURSEMENT';

    IF v_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Setup error: transaction type LOAN_DISBURSEMENT is missing. Run the database seeders.';
    END IF;

    START TRANSACTION;

    -- Loan first, then account (the order every loan procedure uses).
    SELECT status, customer_id, branch_id, account_id, staff_approved_by, loan_amount, interest_rate,
           loan_term_months, repayment_plan, monthly_instalment, total_interest, total_repayable
      INTO v_status, v_customer_id, v_branch_id, v_account_id, v_staff_approved_by, v_amount, v_rate,
           v_term, v_plan, v_instalment, v_total_interest, v_total_repayable
      FROM loan WHERE loan_id = p_loan_id FOR UPDATE;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan not found.';
    END IF;
    IF v_status <> 'AWAITING_ADMIN' THEN
        SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'This loan is no longer awaiting approval.';
    END IF;
    IF v_staff_approved_by IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This loan has not been approved by staff.';
    END IF;
    IF v_staff_approved_by = v_employee_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The same person can''t make both approvals.';
    END IF;

    SELECT status, customer_id, account_number, balance
      INTO v_account_status, v_account_customer_id, v_account_number, v_balance
      FROM account WHERE account_id = v_account_id FOR UPDATE;

    IF v_account_customer_id <> v_customer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The disbursement account does not belong to the borrower.';
    END IF;
    IF v_account_status = 'FROZEN' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The disbursement account is frozen.';
    END IF;
    IF v_account_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The disbursement account is closed.';
    END IF;

    SELECT kyc_status INTO v_kyc_status FROM customer WHERE customer_id = v_customer_id;
    SELECT status INTO v_login_status FROM users WHERE customer_id = v_customer_id;

    IF v_kyc_status IS NULL OR v_kyc_status <> 'VERIFIED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The customer is not KYC verified.';
    END IF;
    IF v_login_status IS NULL OR v_login_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The customer''s login is not active.';
    END IF;

    -- Schedule: a row is bad if any check fails or any value is missing (NULL makes the AND NULL).
    WITH s AS (
        SELECT jt.* FROM {$schedule}
    ), o AS (
        SELECT s.*,
               COALESCE(LAG(s.balance_after) OVER (ORDER BY s.n), v_amount) AS opening,
               COUNT(*) OVER () AS total
          FROM s
    )
    SELECT COUNT(*), MIN(n), MAX(n), COUNT(DISTINCT n), SUM(principal), SUM(interest), SUM(amount),
           SUM(IF(
               n IS NOT NULL AND due_date IS NOT NULL AND principal IS NOT NULL AND interest IS NOT NULL
               AND amount IS NOT NULL AND balance_after IS NOT NULL
               AND principal = ROUND(principal, 2) AND interest = ROUND(interest, 2)
               AND amount = ROUND(amount, 2) AND balance_after = ROUND(balance_after, 2)
               AND principal > 0 AND interest >= 0
               AND amount = principal + interest
               AND balance_after = opening - principal
               AND (n <> total OR balance_after = 0)
               AND interest = IF(v_plan = 'MONTHLY', ROUND(opening * v_rate / 1200, 2), ROUND(v_amount * v_rate * v_term / 1200, 2))
               AND (v_plan <> 'MONTHLY' OR n = total OR amount = v_instalment)
               AND due_date = DATE_ADD(UTC_DATE(), INTERVAL IF(v_plan = 'MONTHLY', n, v_term) MONTH),
               0, 1))
      INTO v_rows, v_min_n, v_max_n, v_distinct_n, v_sum_principal, v_sum_interest, v_sum_amount, v_bad_rows
      FROM o;

    IF NOT COALESCE(
        v_rows = IF(v_plan = 'MONTHLY', v_term, 1)
        AND v_min_n = 1 AND v_max_n = v_rows AND v_distinct_n = v_rows
        AND v_bad_rows = 0
        AND v_sum_principal = v_amount
        AND v_sum_interest = v_total_interest AND v_sum_interest > 0
        AND v_sum_amount = v_total_repayable,
        FALSE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The repayment schedule is invalid.';
    END IF;

    SET v_balance_after = v_balance + v_amount;

    IF v_balance_after > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account cannot hold this amount.';
    END IF;

    UPDATE account SET balance = v_balance_after WHERE account_id = v_account_id;

    INSERT INTO transactions (account_id, transaction_type_id, branch_id, employee_id, transfer_id, amount, channel, balance_after, description)
    VALUES (v_account_id, v_type_id, v_branch_id, v_employee_id, NULL, v_amount, 'BRANCH', v_balance_after, CONCAT('Loan #', p_loan_id, ' disbursement'));

    SET v_transaction_id = LAST_INSERT_ID();

    INSERT INTO loan_instalment (loan_id, instalment_number, due_date, principal, interest, amount, balance_after, status)
    SELECT p_loan_id, jt.n, jt.due_date, jt.principal, jt.interest, jt.amount, jt.balance_after, 'UNPAID'
      FROM {$schedule};

    UPDATE loan
       SET status = 'ACTIVE', approved_by = v_employee_id, approval_date = NOW(), disbursement_transaction_id = v_transaction_id
     WHERE loan_id = p_loan_id;

    INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
    VALUES (
        p_performed_by, 'LOAN_APPROVED_ADMIN', 'loan', p_loan_id,
        JSON_OBJECT('before', JSON_OBJECT('status', 'AWAITING_ADMIN'), 'after', JSON_OBJECT('status', 'ACTIVE'),
                    'amount', v_amount, 'repayment_plan', v_plan, 'interest_rate', v_rate, 'term_months', v_term,
                    'monthly_instalment', v_instalment, 'total_interest', v_total_interest, 'total_repayable', v_total_repayable,
                    'account_number', v_account_number, 'transaction_id', v_transaction_id,
                    'balance_before', v_balance, 'balance_after', v_balance_after)
    );

    COMMIT;

    SELECT p_loan_id AS loan_id, v_transaction_id AS transaction_id, v_account_number AS account_number,
           v_amount AS amount, CAST(v_balance_after AS DECIMAL(15,2)) AS balance_after;
END
SQL;
    }
};
