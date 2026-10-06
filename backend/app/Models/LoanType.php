<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Admin-managed. The rate is copied onto each loan at apply time, so changing it never affects existing loans. */
#[Fillable(['type_name', 'interest_rate'])]
class LoanType extends Model
{
    protected $table = 'loan_type';

    protected $primaryKey = 'loan_type_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:2',
        ];
    }
}
