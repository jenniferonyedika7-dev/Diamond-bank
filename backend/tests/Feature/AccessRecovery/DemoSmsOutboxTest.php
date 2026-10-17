<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DemoSms;
use App\Models\User;
use App\Services\SmsSender;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| The demo SMS outbox (and the login page's link to it) exists only when
| APP_ENV=local. Elsewhere the route answers 404 and features() says false.
*/

it('is hidden outside the local environment', function () {
    expect(app()->environment())->toBe('testing');
    app(SmsSender::class)->send(bankCustomer(), 'Diamond Bank: hello', 'TEST');

    $this->getJson('/api/v1/demo/sms-outbox')->assertNotFound()->assertJsonMissingPath('data.0');
    $this->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.demo_sms_outbox', false);
});

it('shows the last 24 hours of texts, newest first, when local', function () {
    $this->app['env'] = 'local';
    $customer = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow', 'phone' => '7000123']);
    $staff = User::factory()->staff()->create();
    $sms = app(SmsSender::class);

    $sms->send($customer, 'Diamond Bank: old message', 'TEST');
    $this->travel(25)->hours();
    $sms->send($customer, 'Diamond Bank: first', 'TEST');
    $this->travel(1)->minutes();
    $sms->send($staff, 'Diamond Bank: second', 'TEST');

    $this->getJson('/api/v1/features')->assertJsonPath('data.demo_sms_outbox', true);
    $messages = $this->getJson('/api/v1/demo/sms-outbox')->assertOk()->json('data');

    expect(array_column($messages, 'message'))->toBe(['Diamond Bank: second', 'Diamond Bank: first'])
        ->and($messages[1])->toMatchArray(['phone' => '7000123', 'recipient_name' => 'Awa Jallow'])
        ->and($messages[0]['recipient_name'])->toBe($staff->employee->full_name);
});

it('audits every text without its content', function () {
    $customer = bankCustomer(['phone' => '7009876']);
    app(SmsSender::class)->send($customer, 'Diamond Bank: secret 123', 'TEST');

    $this->assertDatabaseHas('audit_log', ['action_type' => 'DEMO_SMS_SENT', 'table_affected' => 'demo_sms_outbox']);
    expect(json_decode(DB::table('audit_log')->where('action_type', 'DEMO_SMS_SENT')->value('details'), true))
        ->toEqual(['recipient_user_id' => $customer->user_id, 'purpose' => 'TEST', 'phone_last4' => '9876']);
});

/*
| A temporary password shows in the outbox only while it can still be used.
| Once used, expired or replaced, the API masks the code and says why.
*/

function outbox(): TestResponse
{
    app()['env'] = 'local';

    return actingFresh(adminUser())->getJson('/api/v1/demo/sms-outbox')->assertOk();
}

/** The outbox entry for the text $user received last with $purpose. */
function outboxEntry(TestResponse $response, User $user, string $purpose): array
{
    $id = DemoSms::query()->where('user_id', $user->user_id)->where('purpose', $purpose)->max('demo_sms_id');

    return collect($response->json('data'))->firstWhere('demo_sms_id', $id);
}

/** Locks $customer out, and an admin approves an UNBLOCK_AND_RESET request raised by $staff. Returns the texted code. */
function issueTemporaryPassword(User $staff, Customer $customer): string
{
    lockoutBlock($customer->user);
    raiseAccessRequest($staff, $customer, ['request_type' => 'UNBLOCK_AND_RESET'])->assertCreated();
    actingFresh(adminUser())->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();

    return temporaryPasswordFor($customer->user);
}

function maskedText(string $code, string $text): string
{
    return str_replace($code, str_repeat('•', 12), $text);
}

describe('temporary passwords in the outbox', function () {
    beforeEach(function () {
        $branch = Branch::factory()->create(['branch_name' => 'Brikama']);
        $this->staff = staffAtBranch($branch);
        $this->customer = customerWithLogin('VERIFIED', $branch->branch_id);
        $this->login = $this->customer->user;
    });

    it('shows the code while it can be used', function () {
        $code = issueTemporaryPassword($this->staff, $this->customer);

        $entry = outboxEntry(outbox(), $this->login, 'TEMPORARY_PASSWORD');
        expect($entry['message'])->toContain($code)
            ->and($entry['message'])->toBe(DemoSms::query()->find($entry['demo_sms_id'])->message)
            ->and($entry['status'])->toBeNull();
    });

    it('masks the code once the user has changed it', function () {
        $code = issueTemporaryPassword($this->staff, $this->customer);
        $text = DemoSms::query()->where('purpose', 'TEMPORARY_PASSWORD')->sole()->message;
        attemptLogin($this->login->user_name, $code)->assertOk();
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => $code, 'password' => 'MyNewSecret123', 'password_confirmation' => 'MyNewSecret123',
        ])->assertOk();

        $response = outbox();
        $entry = outboxEntry($response, $this->login, 'TEMPORARY_PASSWORD');
        expect($entry['message'])->toBe(maskedText($code, $text))
            ->and($entry['message'])->toContain('Your temporary password is ••••••••••••. It expires')
            ->and($entry['status'])->toBe('USED')
            ->and($entry['status_at'])->toBe(DemoSms::query()->find($entry['demo_sms_id'])->used_at->toDateTimeString())
            ->and($response->getContent())->not->toContain($code);
    });

    it('masks the code once it has expired', function () {
        // Shorter than the outbox's 24 hours: at the default 1440 minutes the text leaves the outbox as the code expires.
        config(['bank.temp_password_expiry_minutes' => 30]);
        $code = issueTemporaryPassword($this->staff, $this->customer);
        $this->travel(config('bank.temp_password_expiry_minutes') - 1)->minutes();
        expect(outboxEntry(outbox(), $this->login, 'TEMPORARY_PASSWORD')['status'])->toBeNull();

        $this->travel(2)->minutes();
        $response = outbox();

        expect(outboxEntry($response, $this->login, 'TEMPORARY_PASSWORD'))->toMatchArray(['status' => 'EXPIRED', 'status_at' => null])
            ->and($response->getContent())->not->toContain($code);
    });

    it('masks the code once a newer temporary password replaces it', function () {
        $first = issueTemporaryPassword($this->staff, $this->customer);
        $second = issueTemporaryPassword($this->staff, $this->customer);   // locked out again before using the first

        $response = outbox();
        $entries = collect($response->json('data'))->filter(fn ($e) => str_contains($e['message'], 'temporary password is'))->values();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]['message'])->toContain($second)
            ->and($entries[0]['status'])->toBeNull()
            ->and($entries[1]['status'])->toBe('REPLACED')
            ->and($response->getContent())->not->toContain($first);
    });

    it('leaves the other texts exactly as sent', function () {
        lockoutBlock($this->login);
        raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_ONLY'])->assertCreated();
        actingFresh(adminUser())->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/reject', ['reason' => 'No ID'])->assertOk();
        raiseAccessRequest($this->staff, $this->customer, ['request_type' => 'UNBLOCK_ONLY'])->assertCreated();
        actingFresh(adminUser())->postJson('/api/v1/admin/access-requests/'.pendingRequestId().'/approve')->assertOk();

        $entries = collect(outbox()->json('data'))->sortBy('demo_sms_id')->values();
        expect($entries->pluck('message')->all())->toBe(smsTexts($this->login))
            ->and($entries->pluck('status')->unique()->all())->toBe([null])
            ->and($entries)->toHaveCount(4);
    });

    it('marks nothing used on an ordinary password change', function () {
        $code = issueTemporaryPassword($this->staff, $this->customer);
        $other = bankCustomer();
        attemptLogin($other->user_name, 'password1')->assertOk();
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password1', 'password' => 'MyNewSecret123', 'password_confirmation' => 'MyNewSecret123',
        ])->assertOk();

        expect(DemoSms::query()->whereNotNull('used_at')->count())->toBe(0)
            ->and(outboxEntry(outbox(), $this->login, 'TEMPORARY_PASSWORD')['message'])->toContain($code);
    });
});

it('masks every code generate() can make, from the same alphabet and length', function () {
    foreach (range(1, 200) as $i) {
        $code = TemporaryPassword::generate();
        $text = "Diamond Bank: Your temporary password is {$code}. It expires in 24 hours. You will be asked to change it when you log in. Never share this code.";

        $masked = DemoSms::maskCode($text);

        expect($masked)->not->toContain($code)
            ->and($masked)->toBe(str_replace($code, str_repeat('•', TemporaryPassword::LENGTH), $text))
            ->and(substr_count($masked, '•'))->toBe(TemporaryPassword::LENGTH);
    }
});
