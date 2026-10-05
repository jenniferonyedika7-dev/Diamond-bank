<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a login and writes USERNAME_CHANGED (old -> new). Only user_name
 * changes: the session holds the user_id and Sanctum's AuthenticateSession
 * checks the password hash, so the user's open sessions keep working and
 * their next /auth/me shows the new name. Uniqueness is checked by
 * UsernameRequest; the unique index catches two admins racing.
 */
class ChangeUsername
{
    public function __construct(private AuditLogger $audit) {}

    public function __invoke(User $user, string $newUserName): void
    {
        try {
            DB::transaction(function () use ($user, $newUserName) {
                $current = DB::table('users')->where('user_id', $user->user_id)->lockForUpdate()->value('user_name');
                if ($current === $newUserName) {
                    throw ValidationException::withMessages(['user_name' => 'This is already the username.']);
                }

                DB::table('users')->where('user_id', $user->user_id)->update(['user_name' => $newUserName, 'updated_at' => now()]);

                $this->audit->log('USERNAME_CHANGED', 'users', $user->user_id, [
                    ...($user->customer_id ? ['customer_id' => $user->customer_id] : ['employee_id' => $user->employee_id]),
                    'before' => ['user_name' => $current],
                    'after' => ['user_name' => $newUserName],
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['user_name' => 'The user name has already been taken.']);
            }
            throw $e;
        }

        $user->user_name = $newUserName;
        $user->syncOriginalAttribute('user_name');
    }
}
