<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request to get back into a login locked by wrong passwords (or
 * holding an expired temporary password): raised by staff at the home branch,
 * approved or rejected by an administrator. Written only by the access request
 * controllers, so nothing is fillable.
 */
class AccessRequest extends Model
{
    public const CHECKS = ['dob_confirmed', 'phone_confirmed', 'history_reviewed', 'declaration_confirmed'];

    protected $table = 'access_request';

    protected $primaryKey = 'access_request_id';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'dob_confirmed' => 'boolean',
            'phone_confirmed' => 'boolean',
            'history_reviewed' => 'boolean',
            'declaration_confirmed' => 'boolean',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }
}
