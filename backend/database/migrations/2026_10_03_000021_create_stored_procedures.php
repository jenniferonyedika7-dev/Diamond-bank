<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Notes for callers:
 * - Call with DB::select('CALL sp_...(?, ?, ...)', [...]); the result row is the final SELECT.
 * - Do NOT call inside DB::transaction(): the procedures manage their own transaction, and
 *   START TRANSACTION implicitly commits whatever the caller had open.
 * - Amount parameters are DECIMAL(20,6) so that inputs with more than 2 decimals can be
 *   detected and rejected instead of being silently rounded by MySQL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Procedures are not removed by migrate:fresh (it only drops tables), so drop first.
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_open_account');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_open_account(
    IN p_customer_id BIGINT UNSIGNED,
    IN p_account_type_id BIGINT UNSIGNED,
    IN p_branch_id BIGINT UNSIGNED,
    IN p_initial_deposit DECIMAL(20,6),
    IN p_currency_code CHAR(3),
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    DECLARE v_user_status VARCHAR(10);
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_employee_id BIGINT UNSIGNED;
    DECLARE v_kyc_status VARCHAR(10);
    DECLARE v_minimum_balance DECIMAL(15,2);
    DECLARE v_deposit_type_id BIGINT UNSIGNED;
    DECLARE v_deposit DECIMAL(15,2);
    DECLARE v_currency CHAR(3);
    DECLARE v_account_id BIGINT UNSIGNED;
    DECLARE v_account_number VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Performer: must exist, be ACTIVE, and be staff or admin.
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
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only staff or administrators can open accounts.';
    END IF;

    -- Customer: must exist and have verified KYC.
    SELECT kyc_status INTO v_kyc_status FROM customer WHERE customer_id = p_customer_id;

    IF v_kyc_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer not found.';
    END IF;
    IF v_kyc_status <> 'VERIFIED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The customer must be KYC verified before an account can be opened.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM branch WHERE branch_id = p_branch_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Branch not found.';
    END IF;

    SELECT minimum_balance INTO v_minimum_balance FROM account_type WHERE account_type_id = p_account_type_id;

    IF v_minimum_balance IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account type not found.';
    END IF;

    -- Initial deposit.
    IF p_initial_deposit IS NULL OR p_initial_deposit < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The initial deposit cannot be negative.';
    END IF;
    IF p_initial_deposit <> ROUND(p_initial_deposit, 2) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amounts can have at most 2 decimal places.';
    END IF;
    IF p_initial_deposit > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The initial deposit is too large.';
    END IF;

    SET v_deposit = p_initial_deposit;

    IF v_deposit < v_minimum_balance THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The initial deposit is below the minimum balance for this account type.';
    END IF;

    -- Currency: exactly 3 letters (case-sensitive match after upper-casing).
    SET v_currency = UPPER(TRIM(p_currency_code));

    IF v_currency IS NULL OR NOT REGEXP_LIKE(v_currency, '^[A-Z]{3}$', 'c') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Currency code must be 3 letters, for example GMD.';
    END IF;

    -- Lookup data.
    SELECT transaction_type_id INTO v_deposit_type_id FROM transaction_type WHERE type_name = 'DEPOSIT';

    IF v_deposit_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Setup error: transaction type DEPOSIT is missing. Run the database seeders.';
    END IF;

    START TRANSACTION;

    INSERT INTO account (customer_id, branch_id, account_type_id, account_number, balance, currency_code, status)
    VALUES (p_customer_id, p_branch_id, p_account_type_id, LEFT(REPLACE(UUID(), '-', ''), 20), v_deposit, v_currency, 'ACTIVE');

    SET v_account_id = LAST_INSERT_ID();
    SET v_account_number = CONCAT('DB', LPAD(p_branch_id, 3, '0'), LPAD(v_account_id, 7, '0'));

    UPDATE account SET account_number = v_account_number WHERE account_id = v_account_id;

    IF v_deposit > 0 THEN
        INSERT INTO transactions (account_id, transaction_type_id, branch_id, employee_id, transfer_id, amount, channel, balance_after, description)
        VALUES (v_account_id, v_deposit_type_id, p_branch_id, v_employee_id, NULL, v_deposit, 'BRANCH', v_deposit, 'Initial deposit');
    END IF;

    INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
    VALUES (
        p_performed_by, 'ACCOUNT_OPENED', 'account', v_account_id,
        JSON_OBJECT('account_number', v_account_number, 'customer_id', p_customer_id,
                    'account_type_id', p_account_type_id, 'branch_id', p_branch_id,
                    'initial_deposit', v_deposit, 'currency_code', v_currency)
    );

    COMMIT;

    SELECT v_account_id AS account_id, v_account_number AS account_number, v_deposit AS balance;
END
SQL);

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_transfer_funds');
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_transfer_funds(
    IN p_from_account_id BIGINT UNSIGNED,
    IN p_to_account_number VARCHAR(20),
    IN p_amount DECIMAL(20,6),
    IN p_description VARCHAR(255),
    IN p_channel VARCHAR(10),
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    DECLARE v_amount DECIMAL(15,2);
    DECLARE v_channel VARCHAR(10);
    DECLARE v_user_status VARCHAR(10);
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_user_customer_id BIGINT UNSIGNED;
    DECLARE v_employee_id BIGINT UNSIGNED;
    DECLARE v_to_account_id BIGINT UNSIGNED;
    DECLARE v_out_type_id BIGINT UNSIGNED;
    DECLARE v_in_type_id BIGINT UNSIGNED;
    DECLARE v_from_status VARCHAR(10);
    DECLARE v_from_currency CHAR(3);
    DECLARE v_from_customer_id BIGINT UNSIGNED;
    DECLARE v_from_balance DECIMAL(15,2);
    DECLARE v_from_type_id BIGINT UNSIGNED;
    DECLARE v_to_status VARCHAR(10);
    DECLARE v_to_currency CHAR(3);
    DECLARE v_to_balance DECIMAL(15,2);
    DECLARE v_minimum_balance DECIMAL(15,2);
    DECLARE v_from_after DECIMAL(15,2);
    DECLARE v_to_after DECIMAL(16,2);
    DECLARE v_transfer_id BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    -- Amount.
    IF p_amount IS NULL OR p_amount <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The transfer amount must be greater than zero.';
    END IF;
    IF p_amount <> ROUND(p_amount, 2) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Amounts can have at most 2 decimal places.';
    END IF;
    IF p_amount > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The transfer amount is too large.';
    END IF;

    SET v_amount = p_amount;

    -- Channel.
    SET v_channel = UPPER(TRIM(p_channel));

    IF v_channel IS NULL OR v_channel NOT IN ('BRANCH', 'ONLINE', 'MOBILE', 'ATM') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Channel must be BRANCH, ONLINE, MOBILE or ATM.';
    END IF;

    -- Performer.
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
    IF v_role_name = 'customer' AND v_user_customer_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Your login is not linked to a customer profile.';
    END IF;

    -- Accounts.
    IF NOT EXISTS (SELECT 1 FROM account WHERE account_id = p_from_account_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source account not found.';
    END IF;

    SELECT account_id INTO v_to_account_id FROM account WHERE account_number = p_to_account_number;

    IF v_to_account_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Destination account not found.';
    END IF;
    IF v_to_account_id = p_from_account_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You cannot transfer money to the same account.';
    END IF;

    -- Lookup data.
    SELECT transaction_type_id INTO v_out_type_id FROM transaction_type WHERE type_name = 'TRANSFER_OUT';
    SELECT transaction_type_id INTO v_in_type_id FROM transaction_type WHERE type_name = 'TRANSFER_IN';

    IF v_out_type_id IS NULL OR v_in_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Setup error: transaction types TRANSFER_OUT / TRANSFER_IN are missing. Run the database seeders.';
    END IF;

    START TRANSACTION;

    -- Lock both rows in ascending account_id order so concurrent transfers cannot deadlock.
    IF p_from_account_id < v_to_account_id THEN
        SELECT status, currency_code, customer_id, balance, account_type_id
          INTO v_from_status, v_from_currency, v_from_customer_id, v_from_balance, v_from_type_id
          FROM account WHERE account_id = p_from_account_id FOR UPDATE;
        SELECT status, currency_code, balance
          INTO v_to_status, v_to_currency, v_to_balance
          FROM account WHERE account_id = v_to_account_id FOR UPDATE;
    ELSE
        SELECT status, currency_code, balance
          INTO v_to_status, v_to_currency, v_to_balance
          FROM account WHERE account_id = v_to_account_id FOR UPDATE;
        SELECT status, currency_code, customer_id, balance, account_type_id
          INTO v_from_status, v_from_currency, v_from_customer_id, v_from_balance, v_from_type_id
          FROM account WHERE account_id = p_from_account_id FOR UPDATE;
    END IF;

    IF v_from_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The source account is not active.';
    END IF;
    IF v_to_status <> 'ACTIVE' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The destination account is not active.';
    END IF;
    IF v_from_currency <> v_to_currency THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Both accounts must use the same currency.';
    END IF;
    IF v_role_name = 'customer' AND v_from_customer_id <> v_user_customer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You can only transfer from your own accounts.';
    END IF;

    SELECT minimum_balance INTO v_minimum_balance FROM account_type WHERE account_type_id = v_from_type_id;

    IF v_from_balance - v_amount < v_minimum_balance THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient funds: this transfer would take the account below its minimum balance.';
    END IF;

    SET v_from_after = v_from_balance - v_amount;
    SET v_to_after = v_to_balance + v_amount;

    IF v_to_after > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The destination account cannot hold this amount.';
    END IF;

    UPDATE account SET balance = v_from_after WHERE account_id = p_from_account_id;
    UPDATE account SET balance = v_to_after WHERE account_id = v_to_account_id;

    INSERT INTO transfer (from_account_id, to_account_id, amount, status)
    VALUES (p_from_account_id, v_to_account_id, v_amount, 'COMPLETED');

    SET v_transfer_id = LAST_INSERT_ID();

    -- employee_id is recorded only when staff/admin performed the transfer.
    INSERT INTO transactions (account_id, transaction_type_id, branch_id, employee_id, transfer_id, amount, channel, balance_after, description)
    VALUES
        (p_from_account_id, v_out_type_id, NULL, IF(v_role_name = 'customer', NULL, v_employee_id), v_transfer_id, v_amount, v_channel, v_from_after, p_description),
        (v_to_account_id, v_in_type_id, NULL, IF(v_role_name = 'customer', NULL, v_employee_id), v_transfer_id, v_amount, v_channel, v_to_after, p_description);

    INSERT INTO audit_log (user_id, action_type, table_affected, record_id, details)
    VALUES (
        p_performed_by, 'TRANSFER', 'transfer', v_transfer_id,
        JSON_OBJECT('from', p_from_account_id, 'to', v_to_account_id,
                    'to_account_number', p_to_account_number, 'amount', v_amount, 'channel', v_channel)
    );

    COMMIT;

    SELECT v_transfer_id AS transfer_id, v_from_after AS balance_after, v_amount AS amount;
END
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_open_account');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_transfer_funds');
    }
};
