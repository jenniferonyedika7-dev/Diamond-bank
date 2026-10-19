<?php

use App\Models\DemoSms;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| 2026_10_18_000001 adds purpose and used_at to demo_sms_outbox and backfills
| existing rows from audit_log: purpose from each text's DEMO_SMS_SENT row,
| used_at from the user's first PASSWORD_CHANGED (on users) after the text.
*/

it('backfills purpose and used_at for rows sent before the columns existed', function () {
    $migration = require database_path('migrations/2026_10_18_000001_add_purpose_and_used_at_to_demo_sms_outbox.php');
    $migration->down();
    expect(Schema::hasColumn('demo_sms_outbox', 'purpose'))->toBeFalse();

    $used = bankCustomer();
    $unused = bankCustomer();
    $sentAt = now()->subHours(3)->startOfSecond();
    $insert = fn ($user, string $text, string $at) => DB::table('demo_sms_outbox')->insertGetId([
        'user_id' => $user->user_id, 'phone' => '870000000', 'message' => Crypt::encryptString($text), 'sent_at' => $at,
    ], 'demo_sms_id');
    $audit = fn (string $action, string $table, int $record, ?array $details, string $at) => DB::table('audit_log')->insert([
        'action_type' => $action, 'table_affected' => $table, 'record_id' => $record,
        'details' => $details === null ? null : json_encode($details), 'action_timestamp' => $at,
    ]);

    $usedSms = $insert($used, 'Diamond Bank: Your temporary password is ABCDEFGHJKmn. It expires in 24 hours.', $sentAt);
    $audit('DEMO_SMS_SENT', 'demo_sms_outbox', $usedSms, ['purpose' => 'TEMPORARY_PASSWORD'], $sentAt);
    // A decoy on another table, and one before the text: neither is the change of this password.
    $audit('PASSWORD_CHANGED', 'customer', $used->user_id, null, $sentAt->copy()->addMinutes(5));
    $audit('PASSWORD_CHANGED', 'users', $used->user_id, null, $sentAt->copy()->subDay());
    $audit('PASSWORD_CHANGED', 'users', $used->user_id, null, $sentAt->copy()->addMinutes(20));
    $audit('PASSWORD_CHANGED', 'users', $used->user_id, null, $sentAt->copy()->addMinutes(40));

    $unusedSms = $insert($unused, 'Diamond Bank: Your temporary password is PQRSTUVWXYZa. It expires in 24 hours.', $sentAt);
    $audit('DEMO_SMS_SENT', 'demo_sms_outbox', $unusedSms, ['purpose' => 'TEMPORARY_PASSWORD'], $sentAt);
    $otherSms = $insert($used, 'Diamond Bank: Your account has been unblocked.', $sentAt);
    $audit('DEMO_SMS_SENT', 'demo_sms_outbox', $otherSms, ['purpose' => 'ACCOUNT_UNBLOCKED'], $sentAt);
    $orphanSms = $insert($unused, 'Diamond Bank: Your temporary password is bcdefghijk23. It expires in 24 hours.', $sentAt);

    $migration->up();

    $rows = DB::table('demo_sms_outbox')->get(['demo_sms_id', 'purpose', 'used_at'])->keyBy('demo_sms_id');
    expect($rows[$usedSms]->purpose)->toBe('TEMPORARY_PASSWORD')
        ->and($rows[$usedSms]->used_at)->toBe($sentAt->copy()->addMinutes(20)->toDateTimeString())
        ->and($rows[$unusedSms]->purpose)->toBe('TEMPORARY_PASSWORD')
        ->and($rows[$unusedSms]->used_at)->toBeNull()
        ->and($rows[$otherSms]->purpose)->toBe('ACCOUNT_UNBLOCKED')
        ->and($rows[$otherSms]->used_at)->toBeNull()
        ->and($rows[$orphanSms]->purpose)->toBeNull();

    // No audit match: the outbox still never shows its code.
    $this->app['env'] = 'local';
    $response = $this->getJson('/api/v1/demo/sms-outbox')->assertOk();
    expect($response->getContent())->not->toContain('bcdefghijk23')
        ->and($response->getContent())->not->toContain('ABCDEFGHJKmn')
        ->and($response->getContent())->not->toContain('PQRSTUVWXYZa')
        ->and(collect($response->json('data'))->firstWhere('demo_sms_id', $orphanSms)['message'])
        ->toBe('Diamond Bank: Your temporary password is ••••••••••••. It expires in 24 hours.')
        ->and(DemoSms::query()->find($orphanSms)->message)->toContain('bcdefghijk23');
});
