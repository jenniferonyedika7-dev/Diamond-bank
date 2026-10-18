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
    /** A deadlock or lock wait timeout: MySQL rolled the work back, so trying again is safe. */
    public const BUSY = 'Another transaction on this account was being processed at the same moment. Nothing was changed; please try again.';

    /**
     * @param  list<mixed>  $arguments
     *
     * @throws ProcedureFailed when the procedure signals SQLSTATE 45000 (422), 45001 (409) or 45002 (422,
     *                         daily transfer limit), or MySQL reports a deadlock (1213 / 40001) or lock wait timeout (1205) (409)
     */
    public static function call(string $name, array $arguments): object
    {
        $placeholders = implode(', ', array_fill(0, count($arguments), '?'));

        try {
            return DB::select("CALL {$name}({$placeholders})", $arguments)[0];
        } catch (QueryException $e) {
            $sqlState = (string) $e->getCode();
            $status = match ($sqlState) {
                '45000', '45002' => 422,
                '45001' => 409,
                default => null,
            };
            if ($status !== null) {
                throw new ProcedureFailed($e->errorInfo[2] ?? 'The operation was refused.', $status, $e, $sqlState);
            }
            if ($sqlState === '40001' || in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                throw new ProcedureFailed(self::BUSY, 409, $e, $sqlState);
            }
            throw $e;
        }
    }
}
