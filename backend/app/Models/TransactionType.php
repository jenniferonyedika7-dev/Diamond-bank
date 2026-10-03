<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only lookup: sp_open_account and sp_transfer_funds depend on these names. */
class TransactionType extends Model
{
    protected $table = 'transaction_type';

    protected $primaryKey = 'transaction_type_id';

    public $timestamps = false;
}
