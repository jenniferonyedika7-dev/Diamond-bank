<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'national_id' => fake()->unique()->bothify('EMP-########'),
            'position' => 'Teller',
            'phone' => fake()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'hired_date' => fake()->date(),
        ];
    }
}
