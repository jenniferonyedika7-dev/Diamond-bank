<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Defaults to an ACTIVE customer login. Needs the role table seeded (RoleSeeder).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => fn () => Role::idFor('customer'),
            'customer_id' => Customer::factory(),
            'employee_id' => null,
            'user_name' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password1'),
            'must_change_password' => false,
            'status' => 'ACTIVE',
        ];
    }

    public function customer(): static
    {
        return $this->state(fn () => [
            'role_id' => Role::idFor('customer'),
            'customer_id' => Customer::factory(),
            'employee_id' => null,
        ]);
    }

    public function staff(): static
    {
        return $this->employeeWithRole('staff');
    }

    public function admin(): static
    {
        return $this->employeeWithRole('admin');
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'PENDING']);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => 'BLOCKED']);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    private function employeeWithRole(string $role): static
    {
        return $this->state(fn () => [
            'role_id' => Role::idFor($role),
            'customer_id' => null,
            'employee_id' => Employee::factory(),
        ]);
    }
}
