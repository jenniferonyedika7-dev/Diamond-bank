<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A stored procedure refused the operation (SIGNAL SQLSTATE '45000').
 * The message is the procedure's MESSAGE_TEXT, written for end users;
 * bootstrap/app.php renders it as a 422 in the API envelope.
 */
class ProcedureFailed extends RuntimeException {}
