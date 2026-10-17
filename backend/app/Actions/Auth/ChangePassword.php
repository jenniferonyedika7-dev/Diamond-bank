<?php

namespace App\Actions\Auth;

use App\Models\DemoSms;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Stores a new password, clears must_change_password (and any temporary
 * password's expiry, marking its texts used) and writes a
 * PASSWORD_CHANGED audit row in one transaction. The caller is responsible
 * for validating the current and new password (see ChangePasswordRequest).
 */
class ChangePassword
{
    public function __construct(private AuditLogger $audit) {}

    public function __invoke(Authenticatable $user, string $newPassword): void
    {
        $userId = $user->getAuthIdentifier();

        DB::transaction(function () use ($user, $userId, $newPassword) {
            $hadTemporary = DB::table('users')->where('user_id', $userId)->lockForUpdate()->value('temp_password_expires_at') !== null;

            DB::table('users')->where('user_id', $userId)->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => false,
                'temp_password_expires_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit->log('PASSWORD_CHANGED', 'users', $userId, null, $user);
            if ($hadTemporary) {
                DemoSms::markTemporaryPasswordUsed($userId);   // the demo outbox stops showing the code
            }
        });
    }
}
