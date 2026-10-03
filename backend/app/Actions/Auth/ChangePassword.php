<?php

namespace App\Actions\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Stores a new password, clears must_change_password and writes a
 * PASSWORD_CHANGED audit row in one transaction. The caller (auth-phase
 * controller) is responsible for validating the current and new password.
 */
class ChangePassword
{
    public function __invoke(Authenticatable $user, string $newPassword, ?string $ipAddress = null): void
    {
        $userId = $user->getAuthIdentifier();

        DB::transaction(function () use ($userId, $newPassword, $ipAddress) {
            DB::table('users')->where('user_id', $userId)->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => false,
                'updated_at' => now(),
            ]);

            DB::table('audit_log')->insert([
                'user_id' => $userId,
                'action_type' => 'PASSWORD_CHANGED',
                'table_affected' => 'users',
                'record_id' => $userId,
                'ip_address' => $ipAddress,
            ]);
        });
    }
}
