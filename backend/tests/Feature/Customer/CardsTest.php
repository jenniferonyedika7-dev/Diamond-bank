<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->me = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow']);
    $this->other = bankCustomer();
    $this->mine = accountFor($this->me, '1500.00');
    $this->theirs = accountFor($this->other, '800.00');
    $this->classic = cardType('Debit Classic');
    $this->gold = cardType('Debit Gold');
    $this->actingAs($this->me);
});

function requestCard(string $accountNumber, object $type): TestResponse
{
    return test()->postJson('/api/v1/customer/cards', ['account_number' => $accountNumber, 'card_type_id' => $type->card_type_id]);
}

it('requests a debit card on my own active account', function () {
    $id = requestCard($this->mine->account_number, $this->classic)
        ->assertCreated()
        ->assertJsonPath('data.status', 'REQUESTED')
        ->assertJsonPath('data.masked_number', null)
        ->assertJsonPath('data.account_number', $this->mine->account_number)
        ->assertJsonPath('data.card_type.type_name', 'Debit Classic')
        ->json('data.bank_card_id');

    $card = DB::table('bank_card')->where('bank_card_id', $id)->first();
    expect($card->card_number)->toBeNull()
        ->and($card->expiry_date)->toBeNull()
        ->and($card->requested_by)->toBe($this->me->user_id);

    $audit = DB::table('audit_log')->where('action_type', 'CARD_REQUESTED')->sole();
    expect($audit->record_id)->toBe($id)
        ->and($audit->user_id)->toBe($this->me->user_id)
        ->and(json_decode($audit->details, true))->toMatchArray(['account_number' => $this->mine->account_number, 'after' => ['status' => 'REQUESTED']]);
});

it('answers a card request on another customer\'s account with the same 404 as a missing account', function () {
    requestCard($this->theirs->account_number, $this->classic)
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'Account not found.']);
    requestCard('DB0019999999', $this->classic)
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'Account not found.']);

    $this->assertDatabaseCount('bank_card', 0);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_REQUESTED']);
});

it('refuses a card on an account that is not active', function (string $status) {
    $account = accountFor($this->me, '100.00', ['status' => $status]);

    requestCard($account->account_number, $this->classic)
        ->assertUnprocessable()
        ->assertJson(['success' => false, 'message' => 'Cards can only be requested for an active account.']);

    $this->assertDatabaseCount('bank_card', 0);
})->with(['FROZEN', 'CLOSED']);

it('refuses a card until KYC is verified', function () {
    DB::table('customer')->where('customer_id', $this->me->customer_id)->update(['kyc_status' => 'PENDING']);

    requestCard($this->mine->account_number, $this->classic)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your identity must be verified before you can request a card.');
});

it('allows only one live card per account, of any type', function (string $existingStatus, string $requestedType) {
    cardFor($this->mine, $existingStatus, ['card_type_id' => $this->classic->card_type_id]);

    requestCard($this->mine->account_number, $this->{$requestedType})
        ->assertStatus(409)
        ->assertJson(['success' => false, 'message' => 'This account already has an active card or a pending request.']);

    $this->assertDatabaseCount('bank_card', 1);
})->with([
    'pending request, same type' => ['REQUESTED', 'classic'],
    'pending request, other type' => ['REQUESTED', 'gold'],
    'active card, same type' => ['ACTIVE', 'classic'],
    'active card, other type' => ['ACTIVE', 'gold'],
]);

it('lets me block a lost card and request a replacement', function () {
    $lost = cardFor($this->mine, 'ACTIVE');

    $this->postJson("/api/v1/customer/cards/{$lost->bank_card_id}/block", ['reason' => 'Lost at the market'])
        ->assertOk()
        ->assertJsonPath('message', 'Card blocked. You can request a replacement.');

    expect(DB::table('bank_card')->where('bank_card_id', $lost->bank_card_id)->value('status'))->toBe('BLOCKED');
    $audit = DB::table('audit_log')->where('action_type', 'CARD_BLOCKED')->sole();
    expect(json_decode($audit->details, true))->toMatchArray([
        'by' => 'customer', 'last4' => $lost->last4, 'reason' => 'Lost at the market',
        'before' => ['status' => 'ACTIVE'], 'after' => ['status' => 'BLOCKED'],
    ]);

    requestCard($this->mine->account_number, $this->classic)
        ->assertCreated()
        ->assertJsonPath('data.status', 'REQUESTED');
});

it('does not count a rejected request against the account', function () {
    cardFor($this->mine, 'REJECTED', ['rejection_reason' => 'Incomplete documents']);

    requestCard($this->mine->account_number, $this->classic)->assertCreated();
});

it('refuses to block a card that is not mine or not active', function () {
    $theirs = cardFor($this->theirs, 'ACTIVE');
    $this->postJson("/api/v1/customer/cards/{$theirs->bank_card_id}/block")
        ->assertNotFound()
        ->assertJsonPath('message', 'Card not found.');
    expect(DB::table('bank_card')->where('bank_card_id', $theirs->bank_card_id)->value('status'))->toBe('ACTIVE');

    $requested = cardFor($this->mine, 'REQUESTED');
    $this->postJson("/api/v1/customer/cards/{$requested->bank_card_id}/block")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only an active card can be blocked.');

    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_BLOCKED']);
});

it('lists only my cards, masked, never with the number or its hash', function () {
    $active = cardFor($this->mine, 'ACTIVE');
    cardFor($this->mine, 'REJECTED', ['rejection_reason' => 'Incomplete documents']);
    $theirs = cardFor($this->theirs, 'ACTIVE');
    $fullNumber = Crypt::decryptString($active->card_number);

    $response = $this->getJson('/api/v1/customer/cards')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['masked_number' => "**** **** **** {$active->last4}", 'status' => 'ACTIVE'])
        ->assertJsonFragment(['status' => 'REJECTED', 'rejection_reason' => 'Incomplete documents', 'masked_number' => null])
        ->assertJsonMissing(['last4' => $theirs->last4]);

    expect($response->getContent())
        ->not->toContain($fullNumber)
        ->not->toContain('card_number_hash')
        ->not->toContain('"card_number"');
});

it('refuses a credit card type that predates the debit-only rule', function () {
    $credit = DB::table('card_type')->insertGetId(['type_name' => 'Credit Platinum', 'daily_limit' => 1000], 'card_type_id');

    $this->postJson('/api/v1/customer/cards', ['account_number' => $this->mine->account_number, 'card_type_id' => $credit])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only debit cards are supported.');

    $this->assertDatabaseCount('bank_card', 0);
});

it('lists card types for the request form', function () {
    $this->getJson('/api/v1/customer/card-types')
        ->assertOk()
        ->assertJsonPath('data.0.type_name', 'Debit Classic')
        ->assertJsonPath('data.1.type_name', 'Debit Gold');
});

it('refuses card routes to a blocked customer', function () {
    $blocked = User::factory()->blocked()->create([
        'customer_id' => Customer::factory()->create(['kyc_status' => 'VERIFIED'])->customer_id,
    ]);
    $account = accountFor($blocked, '500.00');
    Auth::forgetGuards();
    $this->actingAs($blocked);

    requestCard($account->account_number, $this->classic)
        ->assertForbidden()
        ->assertJsonPath('message', 'Your account is not active.');

    $this->assertDatabaseCount('bank_card', 0);
});
