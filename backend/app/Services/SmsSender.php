<?php

namespace App\Services;

use App\Models\DemoSms;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The bank's only way to text a user. There is no SMS gateway: messages go to
 * the demo_sms_outbox table, shown on the local-only demo outbox page. A real
 * gateway would replace send() and nothing else.
 *
 * The phone is the customer's (customer.phone) or the staff member's
 * (employee.phone). The audit row records the purpose and the phone's last
 * four digits, never the text: it can hold a temporary password.
 */
class SmsSender
{
    public function __construct(private AuditLogger $audit) {}

    public function send(User $to, string $message, string $purpose): void
    {
        $phone = $to->customer_id !== null
            ? DB::table('customer')->where('customer_id', $to->customer_id)->value('phone')
            : DB::table('employee')->where('employee_id', $to->employee_id)->value('phone');

        $sms = DemoSms::query()->create([
            'user_id' => $to->user_id,
            'purpose' => $purpose,
            'phone' => $phone,
            'message' => $message,
            'sent_at' => now(),
        ]);

        $this->audit->log('DEMO_SMS_SENT', 'demo_sms_outbox', $sms->demo_sms_id, [
            'recipient_user_id' => $to->user_id,
            'purpose' => $purpose,
            'phone_last4' => substr((string) $phone, -4),
        ]);
    }
}
