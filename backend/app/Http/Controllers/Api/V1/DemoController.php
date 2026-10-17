<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DemoSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Development aids. The demo SMS outbox stands in for the users' phones
 * (SmsSender writes to it) and only exists when APP_ENV=local: its route is
 * behind 'local.only', and features() tells the login page whether to link it.
 */
class DemoController extends Controller
{
    private const OUTBOX_HOURS = 24;

    public function features(): JsonResponse
    {
        return ApiResponse::success('Features.', ['demo_sms_outbox' => app()->environment('local')]);
    }

    /**
     * Messages from the last 24 hours, newest first. A temporary password is
     * shown only while it can still be used: the recipient's newest one, with
     * must_change_password set and temp_password_expires_at in the future.
     * Otherwise the code is masked and status says why: USED (status_at),
     * REPLACED or EXPIRED. Rows from before purpose was recorded are always masked.
     */
    public function smsOutbox(): JsonResponse
    {
        $messages = DemoSms::query()
            ->where('sent_at', '>=', now()->subHours(self::OUTBOX_HOURS))
            ->orderByDesc('sent_at')
            ->orderByDesc('demo_sms_id')
            ->get();
        $userIds = $messages->pluck('user_id')->unique();

        $recipients = DB::table('users')
            ->leftJoin('customer', 'customer.customer_id', '=', 'users.customer_id')
            ->leftJoin('employee', 'employee.employee_id', '=', 'users.employee_id')
            ->whereIn('users.user_id', $userIds)
            ->get(['users.user_id', 'users.must_change_password', 'users.temp_password_expires_at',
                DB::raw("COALESCE(CONCAT(customer.first_name, ' ', customer.last_name), employee.full_name) as name")])
            ->keyBy('user_id');
        // Each recipient's newest temporary password text, at any age.
        $newest = DemoSms::query()
            ->whereIn('user_id', $userIds)
            ->where('purpose', DemoSms::TEMPORARY_PASSWORD)
            ->groupBy('user_id')
            ->pluck(DB::raw('MAX(demo_sms_id)'), 'user_id');

        return ApiResponse::success('Demo SMS outbox.', $messages->map(function (DemoSms $sms) use ($recipients, $newest) {
            $recipient = $recipients[$sms->user_id] ?? null;
            [$message, $status] = match ($sms->purpose) {
                DemoSms::TEMPORARY_PASSWORD => $this->temporaryPassword($sms, $recipient, (int) ($newest[$sms->user_id] ?? 0)),
                null => [DemoSms::maskCode($sms->message), null],
                default => [$sms->message, null],
            };

            return [
                'demo_sms_id' => $sms->demo_sms_id,
                'phone' => $sms->phone,
                'recipient_name' => $recipient?->name,
                'message' => $message,
                'sent_at' => $sms->sent_at->toDateTimeString(),
                'status' => $status,
                'status_at' => $status === 'USED' ? $sms->used_at->toDateTimeString() : null,
            ];
        }));
    }

    /** @return array{0: string, 1: ?string} the text to show, and why the code is hidden (null when it isn't) */
    private function temporaryPassword(DemoSms $sms, ?object $recipient, int $newestId): array
    {
        $usable = $sms->demo_sms_id === $newestId
            && (bool) $recipient?->must_change_password
            && $recipient->temp_password_expires_at !== null
            && Carbon::parse($recipient->temp_password_expires_at)->isFuture();
        if ($usable) {
            return [$sms->message, null];
        }

        $status = match (true) {
            $sms->demo_sms_id !== $newestId => 'REPLACED',
            $sms->used_at !== null => 'USED',
            default => 'EXPIRED',
        };

        return [DemoSms::maskCode($sms->message), $status];
    }
}
