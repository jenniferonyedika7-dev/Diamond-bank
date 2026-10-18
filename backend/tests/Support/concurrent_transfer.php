<?php

/*
| Test helper, run as a separate PHP process by TransferLimitConcurrencyTest so
| that two database sessions really run at the same time. Connects with the
| DB_* environment the test passes (the testing database only) and prints one
| line of JSON.
|
|   transfer <from_account_id> <to_account_number> <amount> <performed_by> <start_at>
|       waits until the unix time start_at, then CALLs sp_transfer_funds.
|   deadlock <destination_account_id> <source_account_id> <ready_file>
|       opens a transaction, inserts scratch audit rows (so InnoDB prefers to roll
|       back the other, smaller transaction), locks the destination account, writes
|       ready_file, waits 1 s, then locks the source account; finally rolls back.
*/

$database = getenv('DB_DATABASE');
if ($database !== 'bank_db_testing') {
    fwrite(STDERR, "Refusing to run against {$database}: only bank_db_testing.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), $database),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$mode = $argv[1] ?? '';

try {
    if ($mode === 'transfer') {
        [, , $from, $to, $amount, $performedBy, $startAt] = $argv;
        while (microtime(true) < (float) $startAt) {
            usleep(1000);
        }
        $statement = $pdo->prepare('CALL sp_transfer_funds(?, ?, ?, ?, ?, ?)');
        $statement->execute([(int) $from, $to, $amount, 'Concurrency test', 'ONLINE', (int) $performedBy]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'transfer_id' => (int) $row['transfer_id']]), "\n";
    } elseif ($mode === 'deadlock') {
        [, , $destination, $source, $readyFile] = $argv;
        $pdo->beginTransaction();
        for ($i = 0; $i < 5; $i++) {
            $pdo->exec("INSERT INTO audit_log (action_type, table_affected) VALUES ('TEST_DEADLOCK_HELPER', 'account')");
        }
        $pdo->prepare('SELECT account_id FROM account WHERE account_id = ? FOR UPDATE')->execute([(int) $destination]);
        file_put_contents($readyFile, 'ready');
        sleep(1);
        $pdo->prepare('SELECT account_id FROM account WHERE account_id = ? FOR UPDATE')->execute([(int) $source]);
        $pdo->rollBack();
        echo json_encode(['ok' => true]), "\n";
    } else {
        fwrite(STDERR, "Unknown mode {$mode}\n");
        exit(2);
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'sqlstate' => $e->errorInfo[0] ?? null, 'code' => $e->errorInfo[1] ?? null, 'message' => $e->errorInfo[2] ?? $e->getMessage()]), "\n";
}
