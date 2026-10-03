<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['full_name', 'national_id', 'position', 'phone', 'email', 'hired_date'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $table = 'employee';

    protected $primaryKey = 'employee_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'hired_date' => 'date',
        ];
    }
}
