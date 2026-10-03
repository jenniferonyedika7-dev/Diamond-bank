<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * sp_deposit and sp_withdraw: cash in and out at the performer's current branch.
 * Same calling rules as 2026_10_03_000021_create_stored_procedures.php:
 * - Call with DB::select('CALL sp_...(?, ?, ?, ?)', [...]); the result row is the final SELECT.
 * - Do NOT call inside DB::transaction(): the procedures manage their own transaction.
 * - p_amount is DECIMAL(20,6) so more than 2 decimals can be detected and rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['DEPOSIT' => 'sp_deposit', 'WITHDRAWAL' => 'sp_withdraw'] as $type => $name) {
            DB::unprepared("DROP PROCEDURE IF EXISTS {$name}");
            DB::unprepared($this->procedure($name, $type));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_deposit');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_withdraw');
    }

    /**
     * The two procedures differ only in the transaction type, the direction of
     * the balance change and the check made on the new balance.
     */
    private function procedure(string $name, string $type): string
    {
        $isDeposit = $type === 'DEPOSIT';
        $noun = $isDeposit ? 'deposit' : 'withdrawal';
        $newBalance = $isDeposit ? 'v_balance + v_amount' : 'v_balance - v_amount';
        $balanceCheck = $isDeposit
            ? <<<'SQL'
    IF v_balance_after > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account cannot hold this amount.';
    END IF;
SQL
            : <<<'SQL'
    SELECT minimum_balance INTO v_minimum_balance FROM account_type WHERE account_type_id = v_account_type_id;

    IF v_balance_after < v_minimum_balance THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient funds: this withdrawal would take the account below its minimum balance.';
    END IF;
SQL;

        return <<<SQL
CREATE PROCEDURE {$name}(
    IN p_account_number VARCHAR(20),
    IN p_amount DECIMAL(20,6),
    IN p_description VARCHAR(255),
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    DECLARE v_amount DECIMAL(15,2);
    DECLARE v_user_status VARCHAR(10);
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_employee_id BIGINT UNSIGNED;
    DECLARE v_branch_id BIGINT UNSIGNED;
    DECLARE v_account_id BIGINT UNSIGNED;
    DECLARE v_type_id BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(10);
    DECLARE v_balance DECIMAL(15,2);
    DECLARE v_account_type_id BIGINT UNSIGNED;
    DECLARE v_minimum_balance DECIMAL(15,2);
    DECLARE v_balance_after DECIMAL(16,2);
    DECLARE v_transaction_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Amount.
    IF p_amount IS NULL OR p_amount <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The {$noun} amount must be greater than zero.';
    END IF;
    IF p_amount <> ROUND(p_amount, 2) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amounts can have at most 2 decimal places.';
    END IF;
    IF p_amount > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The {$noun} amount is too large.';
    END IF;

    SET v_amount = p_amount;

    -- Performer: ACTIVE staff or admin with a current branch (the branch the cash is handled at).
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
    IF v_role_name NOT IN ('staff', 'admin') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only staff or administrators can make a {$noun}.';
    END IF;

    SELECT branch_id INTO v_branch_id
      FROM employee_branch_lnk
     WHERE employee_id = v_employee_id AND end_date IS NULL
     ORDER BY start_date DESC, employee_branch_lnk_id DESC
     LIMIT 1;

    IF v_branch_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You are not assigned to a branch.';
    END IF;

    -- Account.
    SELECT account_id INTO v_account_id FROM account WHERE account_number = p_account_number;

    IF v_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account not found.';
    END IF;

    -- Lookup data.
    SELECT transaction_type_id INTO v_type_id FROM transaction_type WHERE type_name = '{$type}';

    IF v_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Setup error: transaction type {$type} is missing. Run the database seeders.';
    END IF;

    START TRANSACTION;

    SELECT status, balance, account_type_id
      INTO v_status, v_balance, v_account_type_id
      FROM account WHERE account_id = v_account_id FOR UPDATE;

    IF v_status = 'FROZEN' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account is frozen.';
    END IF;
    IF v_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account is closed.';
    END IF;

    SET v_balance_after = {$newBalance};

{$balanceCheck}

    UPDATE account SET balance = v_balance_after WHERE account_id = v_account_id;

    INSERT INTO transactions (account_id, transaction_type_id, branch_id, employee_id, transfer_id, amount, channel, balance_after, description)
    VALUES (v_account_id, v_type_id, v_branch_id, v_employee_id, NULL, v_amount, 'BRANCH', v_balance_after, p_description);

    SET v_transaction_id = LAST_INSERT_ID();

    INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
    VALUES (
        p_performed_by, '{$type}', 'transactions', v_transaction_id,
        JSON_OBJECT('account_number', p_account_number, 'amount', v_amount,
                    'balance_before', v_balance, 'balance_after', v_balance_after, 'branch_id', v_branch_id)
    );

    COMMIT;

    SELECT v_transaction_id AS transaction_id, p_account_number AS account_number,
           v_amount AS amount, CAST(v_balance_after AS DECIMAL(15,2)) AS balance_after;
END
SQL;
    }
};
