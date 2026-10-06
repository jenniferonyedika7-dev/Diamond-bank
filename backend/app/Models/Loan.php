<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model for loans. Rows are written by the loan controllers (apply,
 * cancel, staff approval, rejection) and by sp_disburse_loan, so nothing is fillable.
 */
class Loan extends Model
{
    /** A customer may have at most one loan in these statuses. */
    public const OPEN_STATUSES = ['PENDING', 'AWAITING_ADMIN', 'ACTIVE'];

    public const STATUSES = ['PENDING', 'AWAITING_ADMIN', 'REJECTED', 'CANCELLED', 'ACTIVE', 'CLOSED'];

    /** The four checks staff record before their approval. */
    public const CHECKS = ['BANK_STATEMENT', 'EMPLOYMENT', 'GUARANTOR_CONTACTED', 'GUARANTOR_OCCUPATION'];

    public const CHECK_LABELS = [
        'BANK_STATEMENT' => 'Bank statement reviewed',
        'EMPLOYMENT' => 'Customer employment verified',
        'GUARANTOR_CONTACTED' => 'Guarantor contacted and aware of the loan',
        'GUARANTOR_OCCUPATION' => 'Guarantor occupation verified',
    ];

    protected $table = 'loan';

    protected $primaryKey = 'loan_id';

    public $timestamps = false;

    protected $guarded = ['*'];
}
