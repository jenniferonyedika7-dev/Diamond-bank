<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type_name', 'interest_rate', 'minimum_balance'])]
class AccountType extends Model
{
    protected $table = 'account_type';

    protected $primaryKey = 'account_type_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:2',
            'minimum_balance' => 'decimal:2',
        ];
    }
}
