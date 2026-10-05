<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Title Case for account type, card type and department names ("SAVINGS" ->
 * "Savings", "debit gold" -> "Debit Gold"). Rows already in Title Case are left
 * alone, so this is safe to run on any copy of the data. If two names would
 * collide on the unique index, nothing is changed and the clash is reported.
 * The admin form requests apply the same casing to new names.
 */
return new class extends Migration
{
    /** table => [primary key, name column] */
    private const TABLES = [
        'account_type' => ['account_type_id', 'type_name'],
        'card_type' => ['card_type_id', 'type_name'],
        'department' => ['department_id', 'department_name'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::TABLES as $table => [$key, $column]) {
                $rows = DB::table($table)->lockForUpdate()->get([$key, $column]);

                $clashes = $rows->groupBy(fn ($row) => Str::lower(Str::title(trim($row->{$column}))))
                    ->filter(fn ($group) => $group->count() > 1);
                if ($clashes->isNotEmpty()) {
                    throw new RuntimeException("{$table}: these names would clash once title-cased: "
                        .$clashes->map(fn ($group) => $group->pluck($column)->implode(' / '))->implode('; ').'. Nothing was changed.');
                }

                foreach ($rows as $row) {
                    $title = Str::title(trim($row->{$column}));
                    if ($title !== $row->{$column}) {
                        DB::table($table)->where($key, $row->{$key})->update([$column => $title]);
                    }
                }
            }
        });
    }

    /** The original casing is not kept, so there is nothing to restore. */
    public function down(): void {}
};
