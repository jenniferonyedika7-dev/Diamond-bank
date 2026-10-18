<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1c: a daily transfer limit per source account.
 *
 * - account_type.daily_transfer_limit (Savings 100,000, Current 500,000; any other type 100,000).
 * - transfer(from_account_id, transfer_date) index for the day's sum.
 * - fn_transfer_day_start(): the bank's day, 00:00-23:59 Africa/Banjul (UTC+0, no daylight saving).
 * - fn_transferred_today(): completed transfers out of an account today, for display (non-locking).
 * - sp_transfer_funds: the same sum as a locking read under the source row lock; over the limit
 *   it signals SQLSTATE 45002 with the amount remaining today. Everything else is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_type', function (Blueprint $table) {
            $table->decimal('daily_transfer_limit', 15, 2)->default(100000.00)->after('minimum_balance');
        });
        DB::statement('ALTER TABLE account_type ADD CONSTRAINT chk_account_type_daily_transfer_limit CHECK (daily_transfer_limit > 0)');
        DB::table('account_type')->where('type_name', 'Savings')->update(['daily_transfer_limit' => '100000.00']);
        DB::table('account_type')->where('type_name', 'Current')->update(['daily_transfer_limit' => '500000.00']);

        Schema::table('transfer', function (Blueprint $table) {
            $table->index(['from_account_id', 'transfer_date'], 'idx_transfer_from_date');
        });

        DB::unprepared('DROP FUNCTION IF EXISTS fn_transfer_day_start');
        DB::unprepared(<<<'SQL'
-- The bank's day is 00:00-23:59 Africa/Banjul (UTC+0, no daylight saving): the start of today's
-- UTC date, in the session time zone that transfer.transfer_date is stored in.
CREATE FUNCTION fn_transfer_day_start() RETURNS DATETIME
NO SQL
RETURN DATE(UTC_TIMESTAMP()) + INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND
SQL);

        DB::unprepared('DROP FUNCTION IF EXISTS fn_transferred_today');
        DB::unprepared(<<<'SQL'
-- Completed transfers out of the account today, on any channel. For display only: a plain,
-- non-locking read. sp_transfer_funds runs the same filter as a locking read.
CREATE FUNCTION fn_transferred_today(p_account_id BIGINT UNSIGNED) RETURNS DECIMAL(15,2)
READS SQL DATA
RETURN (SELECT COALESCE(SUM(amount), 0) FROM transfer
         WHERE from_account_id = p_account_id AND status = 'COMPLETED'
           AND transfer_date >= fn_transfer_day_start() AND transfer_date < fn_transfer_day_start() + INTERVAL 1 DAY)
SQL);

        $this->createTransferProcedure(self::WITH_LIMIT);
    }

    public function down(): void
    {
        $this->createTransferProcedure(self::WITHOUT_LIMIT);
        DB::unprepared('DROP FUNCTION IF EXISTS fn_transferred_today');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_transfer_day_start');

        Schema::table('transfer', function (Blueprint $table) {
            // up()'s index let MySQL drop the foreign key's own from_account_id index; the key needs one back first.
            $table->index('from_account_id', 'transfer_from_account_id_foreign');
        });
        Schema::table('transfer', function (Blueprint $table) {
            $table->dropIndex('idx_transfer_from_date');
        });

        DB::statement('ALTER TABLE account_type DROP CHECK chk_account_type_daily_transfer_limit');
        Schema::table('account_type', function (Blueprint $table) {
            $table->dropColumn('daily_transfer_limit');
        });
    }

    private function createTransferProcedure(string $body): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_transfer_funds');
        DB::unprepared($body);
    }

    /** sp_transfer_funds with the daily limit (G1c). */
    private const WITH_LIMIT = <<<'SQL'
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
    DECLARE v_daily_limit DECIMAL(15,2);
    DECLARE v_sent_today DECIMAL(15,2);
    DECLARE v_day_start DATETIME;
    DECLARE v_message VARCHAR(255);

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

    SELECT minimum_balance, daily_transfer_limit INTO v_minimum_balance, v_daily_limit FROM account_type WHERE account_type_id = v_from_type_id;

    IF v_from_balance - v_amount < v_minimum_balance THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient funds: this transfer would take the account below its minimum balance.';
    END IF;

    SET v_from_after = v_from_balance - v_amount;
    SET v_to_after = v_to_balance + v_amount;

    IF v_to_after > 9999999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The destination account cannot hold this amount.';
    END IF;

    -- Daily limit per source account. The source row is locked FOR UPDATE above, so a second
    -- transfer from this account waits until the first commits. The sum is a locking read
    -- (FOR SHARE): it reads the latest committed rows whatever snapshot the transaction holds.
    -- Same filter as fn_transferred_today (parity-tested).
    SET v_day_start = fn_transfer_day_start();
    SELECT COALESCE(SUM(amount), 0) INTO v_sent_today
      FROM transfer
     WHERE from_account_id = p_from_account_id AND status = 'COMPLETED'
       AND transfer_date >= v_day_start AND transfer_date < v_day_start + INTERVAL 1 DAY
     FOR SHARE;
    IF v_sent_today + v_amount > v_daily_limit THEN
        SET v_message = CONCAT('This transfer is over the daily transfer limit of ', v_from_currency, ' ', FORMAT(v_daily_limit, 2),
            ' for this account. Remaining today: ', v_from_currency, ' ', FORMAT(GREATEST(v_daily_limit - v_sent_today, 0), 2), '.');
        SIGNAL SQLSTATE '45002' SET MESSAGE_TEXT = v_message;
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
SQL;

    /** sp_transfer_funds as created by 2026_10_03_000021_create_stored_procedures (restored by down()). */
    private const WITHOUT_LIMIT = <<<'SQL'
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
SQL;
};
