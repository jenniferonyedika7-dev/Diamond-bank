<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'role';

    protected $primaryKey = 'role_id';

    public $timestamps = false;

    public static function idFor(string $roleName): int
    {
        return static::query()->where('role_name', $roleName)->valueOrFail('role_id');
    }
}
