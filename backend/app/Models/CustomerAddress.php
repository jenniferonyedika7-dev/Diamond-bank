<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $table = 'customer_address';

    protected $primaryKey = 'customer_address_id';

    public $timestamps = false;
}
