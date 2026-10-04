<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->me = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow']);
    $this->other = bankCustomer(['first_name' => 'Lamin', 'last_name' => 'Ceesay']);
    $this->from = accountFor($this->me, '1500.00');   // Savings, minimum 500
    $this->to = accountFor($this->other, '800.00');
    $this->actingAs($this->me);
});

function transferPayload(object $from, object $to, array $overrides = []): array
{
    return array_merge([
        'from_account_number' => $from->account_number,
        'to_account_number' => $to->account_number,
        'amount' => '200.00',
        'description' => 'Rent',
        'password' => 'password1',
    ], $overrides);
}

/** Asserts no money moved: both balances unchanged and no transfer or transaction rows. */
function assertNothingMoved(object $from, object $to): void
{
    expect(balanceOfAccount($from))->toBe($from->balance)
        ->and(balanceOfAccount($to))->toBe($to->balance)
        ->and(DB::table('transfer')->count())->toBe(0)
        ->and(DB::table('transactions')->count())->toBe(0);
}

it('looks up a recipient with a masked name only', function () {
    $response = $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => $this->to->account_number])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Recipient found.',
            'data' => ['account_number' => $this->to->account_number, 'recipient' => 'L*** C***', 'can_receive' => true],
        ]);

    expect($response->getContent())->not->toContain('Lamin')->not->toContain('Ceesay')->not->toContain('800');
});

it('gives the same lookup answer for a missing and a frozen account', function () {
    DB::table('account')->where('account_id', $this->to->account_id)->update(['status' => 'FROZEN']);

    $frozen = $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => $this->to->account_number]);
    $missing = $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => 'DB0019999999']);

    $frozen->assertUnprocessable();
    expect($frozen->status())->toBe($missing->status())
        ->and($frozen->json())->toBe($missing->json())
        ->and($frozen->json('message'))->toBe("This account can't receive transfers. Check the number and try again.");
});

it('transfers money: both balances, both transaction rows, the transfer row and the audit row', function () {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to, ['amount' => '250.50']))
        ->assertCreated()
        ->assertJson(['message' => 'Transfer complete.', 'data' => [
            'amount' => '250.50', 'balance_after' => '1249.50',
            'from_account_number' => $this->from->account_number, 'to_account_number' => $this->to->account_number,
            'recipient' => 'L*** C***',
        ]]);

    expect(balanceOfAccount($this->from))->toBe('1249.50')
        ->and(balanceOfAccount($this->to))->toBe('1050.50');

    $transfer = DB::table('transfer')->first();
    expect($transfer->from_account_id)->toBe($this->from->account_id)
        ->and($transfer->to_account_id)->toBe($this->to->account_id)
        ->and($transfer->amount)->toBe('250.50')
        ->and($transfer->status)->toBe('COMPLETED');

    $types = DB::table('transaction_type')->pluck('type_name', 'transaction_type_id');
    $rows = DB::table('transactions')->where('transfer_id', $transfer->transfer_id)->get()->keyBy('account_id');
    expect($rows)->toHaveCount(2);

    $out = $rows[$this->from->account_id];
    expect($types[$out->transaction_type_id])->toBe('TRANSFER_OUT')
        ->and($out->amount)->toBe('250.50')
        ->and($out->balance_after)->toBe('1249.50')
        ->and($out->channel)->toBe('ONLINE')
        ->and($out->employee_id)->toBeNull()
        ->and($out->description)->toBe('Rent');

    $in = $rows[$this->to->account_id];
    expect($types[$in->transaction_type_id])->toBe('TRANSFER_IN')
        ->and($in->balance_after)->toBe('1050.50')
        ->and($in->channel)->toBe('ONLINE');

    $this->assertDatabaseHas('audit_log', [
        'action_type' => 'TRANSFER', 'user_id' => $this->me->user_id, 'record_id' => $transfer->transfer_id,
    ]);
});

it('refuses a transfer from someone else\'s account', function () {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->to, $this->from))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_account_number' => 'Choose one of your own accounts.']);

    assertNothingMoved($this->from, $this->to);
});

it('refuses a wrong password, moves nothing and audits the attempt without the password', function () {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to, ['password' => 'wrong-pass-123']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'The password is incorrect.']);

    assertNothingMoved($this->from, $this->to);

    $audit = DB::table('audit_log')->where('action_type', 'TRANSFER_PASSWORD_FAILED')->first();
    expect($audit->user_id)->toBe($this->me->user_id)
        ->and($audit->details)->not->toContain('wrong-pass-123')
        ->and(json_decode($audit->details, true)['to_account_number'])->toBe($this->to->account_number);
});

it('refuses to go below the minimum balance and changes nothing', function () {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to, ['amount' => '1000.01']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient funds: this transfer would take the account below its minimum balance.');

    assertNothingMoved($this->from, $this->to);
});

it('refuses frozen source and destination accounts', function () {
    DB::table('account')->where('account_id', $this->from->account_id)->update(['status' => 'FROZEN']);
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The source account is not active.');

    DB::table('account')->where('account_id', $this->from->account_id)->update(['status' => 'ACTIVE']);
    DB::table('account')->where('account_id', $this->to->account_id)->update(['status' => 'FROZEN']);
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The destination account is not active.');

    assertNothingMoved($this->from, $this->to);
});

it('refuses a transfer to the same account', function () {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->from))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_account_number' => "You can't transfer to the same account."]);

    assertNothingMoved($this->from, $this->to);
});

it('refuses invalid amounts', function (mixed $amount, string $message) {
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to, ['amount' => $amount]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => $message]);

    assertNothingMoved($this->from, $this->to);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'TRANSFER_PASSWORD_FAILED']);
})->with([
    'zero' => [0, 'The amount must be greater than zero.'],
    'negative' => [-5, 'The amount must be greater than zero.'],
    'three decimals' => ['10.005', 'Amounts can have at most 2 decimal places.'],
    'too large' => ['100000000.00', 'The amount can be at most 99,999,999.99.'],
]);

it('rate-limits lookups to 10 and transfers to 5 per minute per user', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => 'DB0019999999'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => 'DB0019999999'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('success', false);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to, ['password' => 'nope']))->assertUnprocessable();
    }
    $this->postJson('/api/v1/customer/transfers', transferPayload($this->from, $this->to))
        ->assertStatus(429)
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many attempts. Try again in '));

    assertNothingMoved($this->from, $this->to);
});
