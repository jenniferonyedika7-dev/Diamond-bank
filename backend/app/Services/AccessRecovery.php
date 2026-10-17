<?php

namespace App\Services;

use App\Models\DemoSms;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Getting a user back into a login they can't use: one blocked by the
 * wrong-password lockout (PasswordLockout), or one whose temporary password
 * expired unused. Customers go through an access request (staff at the home
 * branch raise it, an administrator approves it); staff are restored by an
 * administrator directly. Both end in restore().
 */
class AccessRecovery
{
    public const LOCKOUT_BLOCKED = 'LOCKOUT_BLOCKED';

    public const TEMP_PASSWORD_EXPIRED = 'TEMP_PASSWORD_EXPIRED';

    public const UNBLOCK_ONLY = 'UNBLOCK_ONLY';

    public const UNBLOCK_AND_RESET = 'UNBLOCK_AND_RESET';

    public function __construct(private AuditLogger $audit, private SmsSender $sms, private PasswordLockout $lockout) {}

    /** Why $user needs recovering (LOCKOUT_BLOCKED or TEMP_PASSWORD_EXPIRED), or null when they don't. */
    public static function needs(User $user): ?string
    {
        return match (true) {
            PasswordLockout::blockedByLockout($user) => self::LOCKOUT_BLOCKED,
            $user->status === 'ACTIVE' && $user->hasExpiredTemporaryPassword() => self::TEMP_PASSWORD_EXPIRED,
            default => null,
        };
    }

    /** An expired temporary password leaves nothing to sign in with, so it needs a new one. */
    public static function allows(string $reason, string $type): bool
    {
        return ! ($reason === self::TEMP_PASSWORD_EXPIRED && $type === self::UNBLOCK_ONLY);
    }

    /**
     * Reopens the login: ACTIVE, lockout count cleared, and for UNBLOCK_AND_RESET
     * a new temporary password, texted to the user and never returned. Run it
     * inside the caller's transaction, which also writes the caller's own audit row.
     */
    public function restore(User $user, string $type): void
    {
        $columns = ['status' => 'ACTIVE', 'updated_at' => now()];

        if ($type === self::UNBLOCK_AND_RESET) {
            $temporary = TemporaryPassword::generate();
            $expiresAt = now()->addMinutes(TemporaryPassword::lifetimeMinutes());
            $columns += [
                'password' => Hash::make($temporary),
                'must_change_password' => true,
                'temp_password_expires_at' => $expiresAt,
            ];
        }

        DB::table('users')->where('user_id', $user->user_id)->update($columns);
        $this->lockout->cleared($user);
        $user->endSessions();

        if (isset($temporary, $expiresAt)) {
            $this->audit->log('TEMP_PASSWORD_ISSUED', 'users', $user->user_id, ['expires_at' => $expiresAt->toDateTimeString()]);
            $this->sms->send($user, 'Diamond Bank: Your temporary password is '.$temporary.'. It expires in '.TemporaryPassword::lifetimeText()
                .'. You will be asked to change it when you log in. Never share this code.', DemoSms::TEMPORARY_PASSWORD);
        } else {
            $this->sms->send($user, 'Diamond Bank: Your account has been unblocked. If you did not request this, call us immediately.', 'ACCOUNT_UNBLOCKED');
        }
    }
}
