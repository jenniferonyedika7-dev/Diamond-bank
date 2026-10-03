<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'first_name', 'last_name', 'date_of_birth', 'gender', 'national_id', 'phone', 'email', 'kyc_status'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $table = 'customer';

    protected $primaryKey = 'customer_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registration_date' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }
}
