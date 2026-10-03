<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single writer for audit_log. Records the current request's IP.
 * Never pass passwords or other secrets in $details.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * @param  Authenticatable|int|null  $user  the acting user; defaults to the logged-in user
     * @param  array<string, mixed>|null  $details
     */
    public function log(
        string $actionType,
        string $tableAffected,
        ?int $recordId = null,
        ?array $details = null,
        Authenticatable|int|null $user = null,
    ): void {
        $userId = $user instanceof Authenticatable ? $user->getAuthIdentifier() : ($user ?? Auth::id());

        $this->logFor($userId, $actionType, $tableAffected, $recordId, $details);
    }

    /**
     * Like log(), but $userId is taken as given: null stays null (e.g. a failed
     * login for an unknown user_name) instead of falling back to the session user.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function logFor(?int $userId, string $actionType, string $tableAffected, ?int $recordId = null, ?array $details = null): void
    {
        DB::table('audit_log')->insert([
            'user_id' => $userId,
            'action_type' => $actionType,
            'table_affected' => $tableAffected,
            'record_id' => $recordId,
            'details' => $details === null ? null : json_encode($details),
            'ip_address' => $this->request->ip(),
        ]);
    }
}
