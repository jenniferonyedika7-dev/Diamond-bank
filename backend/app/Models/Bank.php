<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['bank_name', 'swift_code', 'established_date'])]
class Bank extends Model
{
    protected $table = 'bank';

    protected $primaryKey = 'bank_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'established_date' => 'date:Y-m-d',
        ];
    }
}
