<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A stored procedure refused the operation. The message is the procedure's
 * MESSAGE_TEXT, written for end users; bootstrap/app.php renders it in the
 * API envelope with $status: 422 for SQLSTATE 45000 (the request can't be
 * done), 409 for 45001 (the record changed state first, e.g. a second approval).
 */
class ProcedureFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
