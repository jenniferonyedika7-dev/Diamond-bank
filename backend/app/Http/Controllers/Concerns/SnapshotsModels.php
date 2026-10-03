<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

trait SnapshotsModels
{
    /** The model's key and fillable columns, serialized with their casts, for audit details. */
    protected function snapshot(Model $model): array
    {
        return [$model->getKeyName() => $model->getKey()]
            + Arr::only($model->attributesToArray(), $model->getFillable());
    }
}
