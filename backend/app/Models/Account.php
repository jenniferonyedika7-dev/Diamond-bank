<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model for accounts. Balances change only through the stored procedures
 * (sp_open_account, sp_deposit, sp_withdraw, sp_transfer_funds), so nothing is fillable.
 */
class Account extends Model
{
    protected $table = 'account';

    protected $primaryKey = 'account_id';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'account_number';
    }

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'opened_date' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountType, $this> */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id', 'account_type_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }
}
