<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * sp_repay_loan: one loan payment, online from the customer's own account or
 * in cash at the loan's branch. Same calling rules as 2026_10_03_000021_create_stored_procedures.php:
 * - Call with DB::select('CALL sp_repay_loan(?, ?, ?, ?, ?, ?, ?)', [...]); the result row is the final SELECT.
 * - Do NOT call inside DB::transaction(): the procedure manages its own transaction.
 * - SQLSTATE 45000 = refused (422). SQLSTATE 45001 = the loan is no longer ACTIVE,
 *   or the amount (or instalment) the caller showed is no longer what is owed (409).
 *
 * The amounts are worked out here, under the row locks (loan, then account,
 * then the UNPAID instalments), using the same rules as App\Support\LoanRepayment,
 * which quotes them to the customer. A change to one needs the matching change
 * to the other; tests/Feature/StoredProcedures/RepayLoanParityTest.php compares them.
 * - INSTALMENT (MONTHLY loans only): the oldest UNPAID row, in full. PAID.
 * - EARLY_PAYOFF: every UNPAID row, SETTLED. MONTHLY: overdue rows and the current
 *   row in full, later rows principal only. SINGLE: principal +
 *   ROUND(P × rate × months / 1200, 2), months started since disbursement
 *   (DATE(approval_date)), at least 1 and at most the term.
 * - ONLINE debits the account (minimum balance applies) and writes a LOAN_PAYMENT
 *   transaction. BRANCH (cash) writes no transaction: transactions rows belong to an account.
 * - The loan becomes CLOSED when no row is UNPAID.
 * Dates are UTC_DATE(), as in sp_disburse_loan, which wrote the due dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_repay_loan');
        DB::unprepared($this->repay());
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_repay_loan');
    }

    private function repay(): string
    {
        // Overdue (due before today) or the current row: its interest is charged.
        $charged = '(due_date < v_today OR instalment_number = v_current_n)';

        return <<<SQL
CREATE PROCEDURE sp_repay_loan(
    IN p_loan_id BIGINT UNSIGNED,
    IN p_payment_type VARCHAR(20),
    IN p_channel VARCHAR(10),
    IN p_account_id BIGINT UNSIGNED,
    IN p_expected_instalment INT,
    IN p_expected_amount DECIMAL(20,6),
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    DECLARE v_type VARCHAR(20);
    DECLARE v_channel VARCHAR(10);
    DECLARE v_user_status VARCHAR(10);
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_user_customer_id BIGINT UNSIGNED;
    DECLARE v_employee_id BIGINT UNSIGNED;
    DECLARE v_branch_id BIGINT UNSIGNED;
    DECLARE v_tx_type_id BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(20);
    DECLARE v_customer_id BIGINT UNSIGNED;
    DECLARE v_loan_branch_id BIGINT UNSIGNED;
    DECLARE v_loan_amount DECIMAL(15,2);
    DECLARE v_rate DECIMAL(5,2);
    DECLARE v_term SMALLINT UNSIGNED;
    DECLARE v_plan VARCHAR(10);
    DECLARE v_approval_date DATETIME;
    DECLARE v_account_status VARCHAR(10);
    DECLARE v_account_customer_id BIGINT UNSIGNED;
    DECLARE v_account_number VARCHAR(20);
    DECLARE v_account_type_id BIGINT UNSIGNED;
    DECLARE v_balance DECIMAL(15,2);
    DECLARE v_minimum_balance DECIMAL(15,2);
    DECLARE v_balance_after DECIMAL(16,2);
    DECLARE v_today DATE;
    DECLARE v_start DATE;
    DECLARE v_months INT;
    DECLARE v_unpaid INT;
    DECLARE v_first_n INT;
    DECLARE v_current_n INT;
    DECLARE v_outstanding DECIMAL(15,2);
    DECLARE v_row_interest DECIMAL(15,2);
    DECLARE v_row_due DATE;
    DECLARE v_principal DECIMAL(15,2);
    DECLARE v_interest_charged DECIMAL(15,2);
    DECLARE v_interest_waived DECIMAL(15,2);
    DECLARE v_amount DECIMAL(15,2);
    DECLARE v_remaining DECIMAL(15,2);
    DECLARE v_transaction_id BIGINT UNSIGNED;
    DECLARE v_payment_id BIGINT UNSIGNED;
    DECLARE v_instalments JSON;
    DECLARE v_loan_status_after VARCHAR(20) DEFAULT 'ACTIVE';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Payment type, channel and the amount the caller showed.
    SET v_type = UPPER(TRIM(p_payment_type));
    SET v_channel = UPPER(TRIM(p_channel));

    IF v_type IS NULL OR v_type NOT IN ('INSTALMENT', 'EARLY_PAYOFF') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose what to pay: the next instalment or everything now.';
    END IF;
    IF v_channel IS NULL OR v_channel NOT IN ('ONLINE', 'BRANCH') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Channel must be ONLINE or BRANCH.';
    END IF;
    IF v_type = 'INSTALMENT' AND p_expected_instalment IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Say which instalment is being paid.';
    END IF;
    IF p_expected_amount IS NULL OR p_expected_amount <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payment amount must be greater than zero.';
    END IF;
    IF p_expected_amount <> ROUND(p_expected_amount, 2) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amounts can have at most 2 decimal places.';
    END IF;
    IF p_expected_amount > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payment amount is too large.';
    END IF;

    -- Performer: the customer (ONLINE), or staff/admin at their current branch (BRANCH).
    SELECT u.status, r.role_name, u.customer_id, u.employee_id
      INTO v_user_status, v_role_name, v_user_customer_id, v_employee_id
      FROM users u
      JOIN role r ON r.role_id = u.role_id
     WHERE u.user_id = p_performed_by;

    IF v_user_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The user performing this action was not found.';
    END IF;
    IF v_user_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Your user account is not active.';
    END IF;

    IF v_channel = 'ONLINE' THEN
        IF v_role_name <> 'customer' OR v_user_customer_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the customer can pay a loan online.';
        END IF;
        IF p_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the account to pay from.';
        END IF;
    ELSE
        IF v_role_name NOT IN ('staff', 'admin') OR v_employee_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only staff can record a cash payment.';
        END IF;
        IF p_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A cash payment is not taken from an account.';
        END IF;

        SELECT branch_id INTO v_branch_id
          FROM employee_branch_lnk
         WHERE employee_id = v_employee_id AND end_date IS NULL
         ORDER BY start_date DESC, employee_branch_lnk_id DESC
         LIMIT 1;

        IF v_branch_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You are not assigned to a branch.';
        END IF;
    END IF;

    SELECT transaction_type_id INTO v_tx_type_id FROM transaction_type WHERE type_name = 'LOAN_PAYMENT';

    IF v_tx_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Setup error: transaction type LOAN_PAYMENT is missing. Run the database seeders.';
    END IF;

    START TRANSACTION;

    -- Loan first, then account (the order every loan procedure uses), then the instalments.
    SELECT status, customer_id, branch_id, loan_amount, interest_rate, loan_term_months, repayment_plan, approval_date
      INTO v_status, v_customer_id, v_loan_branch_id, v_loan_amount, v_rate, v_term, v_plan, v_approval_date
      FROM loan WHERE loan_id = p_loan_id FOR UPDATE;

    IF v_status IS NULL OR (v_channel = 'ONLINE' AND v_customer_id <> v_user_customer_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan not found.';
    END IF;
    IF v_channel = 'BRANCH' AND v_loan_branch_id <> v_branch_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This loan is managed at another branch.';
    END IF;
    IF v_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'This loan is not active.';
    END IF;
    IF v_plan = 'SINGLE' AND v_type = 'INSTALMENT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This loan is repaid in one payment. Choose to pay everything now.';
    END IF;

    IF v_channel = 'ONLINE' THEN
        SELECT status, customer_id, account_number, balance, account_type_id
          INTO v_account_status, v_account_customer_id, v_account_number, v_balance, v_account_type_id
          FROM account WHERE account_id = p_account_id FOR UPDATE;

        IF v_account_customer_id IS NULL OR v_account_customer_id <> v_customer_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose one of your own active accounts.';
        END IF;
        IF v_account_status = 'FROZEN' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account is frozen.';
        END IF;
        IF v_account_status <> 'ACTIVE' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account is closed.';
        END IF;
    END IF;

    SET v_today = UTC_DATE();

    SELECT COUNT(*), MIN(instalment_number), COALESCE(SUM(amount), 0)
      INTO v_unpaid, v_first_n, v_outstanding
      FROM loan_instalment WHERE loan_id = p_loan_id AND status = 'UNPAID' FOR UPDATE;

    IF v_unpaid = 0 THEN
        SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'This loan is not active.';
    END IF;

    IF v_type = 'INSTALMENT' THEN
        SELECT principal, interest, amount
          INTO v_principal, v_interest_charged, v_amount
          FROM loan_instalment WHERE loan_id = p_loan_id AND instalment_number = v_first_n;

        SET v_interest_waived = 0;

        IF p_expected_instalment <> v_first_n THEN
            SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'The amount has changed. Please review it again.';
        END IF;
    ELSEIF v_plan = 'MONTHLY' THEN
        -- The current row: the oldest UNPAID one not yet overdue (NULL when every row is overdue).
        SELECT MIN(instalment_number) INTO v_current_n
          FROM loan_instalment WHERE loan_id = p_loan_id AND status = 'UNPAID' AND due_date >= v_today;

        SELECT SUM(principal), SUM(IF({$charged}, interest, 0)), SUM(IF({$charged}, 0, interest))
          INTO v_principal, v_interest_charged, v_interest_waived
          FROM loan_instalment WHERE loan_id = p_loan_id AND status = 'UNPAID';

        SET v_amount = v_principal + v_interest_charged;
    ELSE
        SELECT principal, interest, due_date
          INTO v_principal, v_row_interest, v_row_due
          FROM loan_instalment WHERE loan_id = p_loan_id AND status = 'UNPAID';

        -- sp_disburse_loan wrote the due date as the disbursement day + term months.
        SET v_start = DATE(v_approval_date);

        IF v_start IS NULL OR v_row_due <> DATE_ADD(v_start, INTERVAL v_term MONTH) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This loan''s dates are inconsistent. Contact support.';
        END IF;

        -- Month n + 1 starts the day after start + n months; each boundary counts from the start.
        SET v_months = 1;
        WHILE v_months < v_term AND DATE_ADD(v_start, INTERVAL v_months MONTH) < v_today DO
            SET v_months = v_months + 1;
        END WHILE;

        SET v_interest_charged = ROUND(v_loan_amount * v_rate * v_months / 1200, 2);
        SET v_interest_waived = v_row_interest - v_interest_charged;
        SET v_amount = v_principal + v_interest_charged;
    END IF;

    IF v_amount <> p_expected_amount THEN
        SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'The amount has changed. Please review it again.';
    END IF;

    SET v_remaining = IF(v_type = 'INSTALMENT', v_outstanding - v_amount, 0);

    IF v_channel = 'ONLINE' THEN
        SELECT minimum_balance INTO v_minimum_balance FROM account_type WHERE account_type_id = v_account_type_id;

        SET v_balance_after = v_balance - v_amount;

        IF v_balance_after < v_minimum_balance THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient funds: this payment would take the account below its minimum balance.';
        END IF;

        UPDATE account SET balance = v_balance_after WHERE account_id = p_account_id;

        INSERT INTO transactions (account_id, transaction_type_id, branch_id, employee_id, transfer_id, amount, channel, balance_after, description)
        VALUES (p_account_id, v_tx_type_id, NULL, NULL, NULL, v_amount, 'ONLINE', v_balance_after, CONCAT('Loan #', p_loan_id, ' repayment'));

        SET v_transaction_id = LAST_INSERT_ID();
    END IF;

    INSERT INTO loan_payment (
        loan_id, branch_id, payment_type, channel, payment_amount, principal_paid, interest_charged, interest_waived,
        payment_date, remaining_balance, status, transaction_id, account_id, paid_by, received_by
    ) VALUES (
        p_loan_id, v_loan_branch_id, v_type, v_channel, v_amount, v_principal, v_interest_charged, v_interest_waived,
        NOW(), v_remaining, 'COMPLETED', v_transaction_id,
        IF(v_channel = 'ONLINE', p_account_id, NULL),
        IF(v_channel = 'ONLINE', p_performed_by, NULL),
        IF(v_channel = 'BRANCH', v_employee_id, NULL)
    );

    SET v_payment_id = LAST_INSERT_ID();

    IF v_type = 'INSTALMENT' THEN
        UPDATE loan_instalment
           SET status = 'PAID', interest_waived = 0, amount_paid = amount, paid_at = NOW(), loan_payment_id = v_payment_id
         WHERE loan_id = p_loan_id AND instalment_number = v_first_n;
    ELSEIF v_plan = 'MONTHLY' THEN
        -- Assignments run left to right, so amount_paid sees the new interest_waived.
        UPDATE loan_instalment
           SET status = 'SETTLED', interest_waived = IF({$charged}, 0, interest), amount_paid = amount - interest_waived,
               paid_at = NOW(), loan_payment_id = v_payment_id
         WHERE loan_id = p_loan_id AND status = 'UNPAID';
    ELSE
        UPDATE loan_instalment
           SET status = 'SETTLED', interest_waived = v_interest_waived, amount_paid = amount - v_interest_waived,
               paid_at = NOW(), loan_payment_id = v_payment_id
         WHERE loan_id = p_loan_id AND status = 'UNPAID';
    END IF;

    SELECT CAST(CONCAT('[', GROUP_CONCAT(instalment_number ORDER BY instalment_number), ']') AS JSON)
      INTO v_instalments
      FROM loan_instalment WHERE loan_payment_id = v_payment_id;

    INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
    VALUES (
        p_performed_by, 'LOAN_PAYMENT', 'loan_payment', v_payment_id,
        JSON_OBJECT('loan_id', p_loan_id, 'payment_type', v_type, 'channel', v_channel,
                    'amount', v_amount, 'principal', v_principal,
                    'interest_charged', v_interest_charged, 'interest_waived', v_interest_waived,
                    'instalments', v_instalments,
                    'before', JSON_OBJECT('status', 'UNPAID'),
                    'after', JSON_OBJECT('status', IF(v_type = 'INSTALMENT', 'PAID', 'SETTLED')),
                    'months_charged', v_months,
                    'account_number', v_account_number, 'transaction_id', v_transaction_id,
                    'balance_before', v_balance, 'balance_after', v_balance_after,
                    'received_by', IF(v_channel = 'BRANCH', v_employee_id, NULL), 'branch_id', v_loan_branch_id,
                    'remaining_balance', v_remaining)
    );

    IF NOT EXISTS (SELECT 1 FROM loan_instalment WHERE loan_id = p_loan_id AND status = 'UNPAID') THEN
        UPDATE loan SET status = 'CLOSED', closed_at = NOW() WHERE loan_id = p_loan_id;

        SET v_loan_status_after = 'CLOSED';

        INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
        VALUES (
            p_performed_by, 'LOAN_CLOSED', 'loan', p_loan_id,
            JSON_OBJECT('before', JSON_OBJECT('status', 'ACTIVE'), 'after', JSON_OBJECT('status', 'CLOSED'),
                        'loan_payment_id', v_payment_id)
        );
    END IF;

    COMMIT;

    SELECT v_payment_id AS loan_payment_id, v_type AS payment_type, v_channel AS channel,
           v_amount AS amount, v_principal AS principal, v_interest_charged AS interest_charged,
           v_interest_waived AS interest_waived, v_transaction_id AS transaction_id,
           v_account_number AS account_number, CAST(v_balance_after AS DECIMAL(15,2)) AS balance_after,
           v_remaining AS remaining_balance, v_loan_status_after AS loan_status;
END
SQL;
    }
};
