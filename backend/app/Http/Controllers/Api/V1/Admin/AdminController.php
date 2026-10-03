<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the /api/v1/admin controllers: audit snapshots and
 * deletes that refuse (409) instead of cascading when a record is in use.
 */
abstract class AdminController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    /** The model's key and fillable columns, serialized with their casts, for audit details. */
    protected function snapshot(Model $model): array
    {
        return [$model->getKeyName() => $model->getKey()]
            + Arr::only($model->attributesToArray(), $model->getFillable());
    }

    /**
     * Deletes $model unless rows reference it, writing a {$action} audit row.
     * $references maps table => [foreign key column, singular label, plural label].
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $references
     */
    protected function deleteUnlessUsed(Model $model, string $noun, array $references, string $action, string $message): JsonResponse
    {
        $uses = [];
        foreach ($references as $table => [$column, $singular, $plural]) {
            $count = DB::table($table)->where($column, $model->getKey())->count();
            if ($count > 0) {
                $uses[] = $count.' '.($count === 1 ? $singular : $plural);
            }
        }

        if ($uses !== []) {
            return ApiResponse::error("This {$noun} can't be deleted: it is used by ".$this->listing($uses).'.', 409);
        }

        $before = $this->snapshot($model);

        try {
            DB::transaction(function () use ($model, $before, $action) {
                $model->delete();
                $this->audit->log($action, $model->getTable(), $model->getKey(), ['before' => $before, 'after' => null]);
            });
        } catch (QueryException $e) {
            // A reference created between the check and the delete; the foreign key refuses it.
            if (($e->errorInfo[1] ?? null) === 1451) {
                return ApiResponse::error("This {$noun} can't be deleted because other records use it.", 409);
            }
            throw $e;
        }

        return ApiResponse::success($message);
    }

    /** @param  list<string>  $items  "a", "a and b", "a, b and c" */
    private function listing(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' and '.$last;
    }
}
