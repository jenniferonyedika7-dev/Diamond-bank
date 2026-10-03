<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Seed the role lookup table. Safe to run repeatedly.
     */
    public function run(): void
    {
        foreach (['customer', 'staff', 'admin'] as $roleName) {
            DB::table('role')->updateOrInsert(['role_name' => $roleName]);
        }
    }
}
