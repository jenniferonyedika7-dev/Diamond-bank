<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['department_name'])]
class Department extends Model
{
    protected $table = 'department';

    protected $primaryKey = 'department_id';

    public $timestamps = false;
}
