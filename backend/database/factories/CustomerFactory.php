<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['M', 'F']),
            'national_id' => fake()->unique()->bothify('NID-########'),
            'phone' => fake()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'kyc_status' => 'PENDING',
        ];
    }
}
