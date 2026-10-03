<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    private const ADMIN_NATIONAL_ID = 'ADMIN-0001';

    /**
     * Create the default administrator. Never overwrites an existing user,
     * and never prints or logs the password.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('AdminSeeder must not run in production. No admin was created.');
        }

        $username = config('admin.username');
        $password = config('admin.password');

        if (blank($username) || blank($password)) {
            throw new RuntimeException('ADMIN_USERNAME and ADMIN_PASSWORD must both be set in .env. No admin was created.');
        }

        if (DB::table('users')->where('user_name', $username)->exists()) {
            $this->command?->warn("Admin user '{$username}' already exists; skipped (password left unchanged).");

            return;
        }

        $adminRoleId = DB::table('role')->where('role_name', 'admin')->value('role_id');

        if ($adminRoleId === null) {
            throw new RuntimeException("Role 'admin' is missing. Run RoleSeeder first.");
        }

        DB::transaction(function () use ($username, $password, $adminRoleId) {
            $employee = DB::table('employee')->where('national_id', self::ADMIN_NATIONAL_ID)->first();

            if ($employee !== null) {
                $linkedUser = DB::table('users')->where('employee_id', $employee->employee_id)->value('user_name');

                if ($linkedUser !== null) {
                    throw new RuntimeException("The admin employee record is already linked to user '{$linkedUser}'. No new admin was created.");
                }

                $employeeId = $employee->employee_id;
            } else {
                $employeeId = DB::table('employee')->insertGetId([
                    'full_name' => 'System Administrator',
                    'national_id' => self::ADMIN_NATIONAL_ID,
                    'position' => 'Administrator',
                    'phone' => '0000000',
                    'email' => 'admin@diamondbank.local',
                    'hired_date' => now()->toDateString(),
                ], 'employee_id');
            }

            $userId = DB::table('users')->insertGetId([
                'role_id' => $adminRoleId,
                'customer_id' => null,
                'employee_id' => $employeeId,
                'user_name' => $username,
                'password' => Hash::make($password),
                'must_change_password' => true,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ], 'user_id');

            DB::table('audit_log')->insert([
                'user_id' => null,
                'action_type' => 'ADMIN_SEEDED',
                'table_affected' => 'users',
                'record_id' => $userId,
                'details' => json_encode(['source' => 'AdminSeeder', 'user_name' => $username]),
            ]);
        });

        $this->command?->info("Admin user '{$username}' created. The password must be changed on first login.");
    }
}
