<?php

namespace App\Support;

use App\Exceptions\ProcedureFailed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Calls the money procedures (sp_open_account, sp_deposit, sp_withdraw, ...).
 * Never call this inside DB::transaction(): each procedure starts its own
 * transaction, which would implicitly commit the caller's.
 */
class StoredProcedure
{
    /**
     * @param  list<mixed>  $arguments
     *
     * @throws ProcedureFailed when the procedure signals SQLSTATE 45000 (422) or 45001 (409)
     */
    public static function call(string $name, array $arguments): object
    {
        $placeholders = implode(', ', array_fill(0, count($arguments), '?'));

        try {
            return DB::select("CALL {$name}({$placeholders})", $arguments)[0];
        } catch (QueryException $e) {
            $status = match ((string) $e->getCode()) {
                '45000' => 422,
                '45001' => 409,
                default => null,
            };
            if ($status !== null) {
                throw new ProcedureFailed($e->errorInfo[2] ?? 'The operation was refused.', $status, $e);
            }
            throw $e;
        }
    }
}
