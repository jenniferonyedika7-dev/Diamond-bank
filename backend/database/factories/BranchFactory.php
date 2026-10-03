<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            // No Bank model yet; reuse the first bank or create one.
            'bank_id' => fn () => DB::table('bank')->value('bank_id') ?? DB::table('bank')->insertGetId([
                'bank_name' => 'Diamond Bank',
                'swift_code' => 'DIAMGMGM',
                'established_date' => '2000-01-01',
            ], 'bank_id'),
            'branch_name' => fake()->unique()->city().' Branch',
            'branch_code' => fake()->unique()->bothify('BR###??'),
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('#######'),
            'opened_date' => '2000-01-01',
        ];
    }
}
