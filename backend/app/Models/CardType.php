<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type_name', 'daily_limit'])]
class CardType extends Model
{
    protected $table = 'card_type';

    protected $primaryKey = 'card_type_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'daily_limit' => 'decimal:2',
        ];
    }
}
