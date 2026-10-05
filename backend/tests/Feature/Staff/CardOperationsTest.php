<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->staff = staffAtBranch($this->branch);

    // A customer whose account is held at this staff member's branch, and one at another branch.
    $this->customer = bankCustomer(['branch_id' => $this->branch->branch_id, 'first_name' => 'Awa', 'last_name' => 'Jallow']);
    $this->account = accountFor($this->customer, '1000.00');
    $this->otherBranchAccount = accountFor(bankCustomer(), '1000.00');

    $this->actingAs($this->staff);
});

function cardStatus(object $card): string
{
    return DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->value('status');
}

it('lists requests for accounts at my branch only', function () {
    $mine = cardFor($this->account, 'REQUESTED');
    cardFor($this->otherBranchAccount, 'REQUESTED');
    cardFor(accountFor($this->customer, '50.00'), 'ACTIVE');

    $this->getJson('/api/v1/staff/cards')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.bank_card_id', $mine->bank_card_id)
        ->assertJsonPath('data.items.0.customer.name', 'Awa Jallow')
        ->assertJsonPath('data.items.0.account_number', $this->account->account_number);

    $this->getJson('/api/v1/staff/cards?status=ACTIVE')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/v1/staff/cards?search=Jallow')->assertOk()->assertJsonPath('data.pagination.total', 1);
});

it('issues a requested card with a Luhn-valid encrypted number and expiry', function () {
    $card = cardFor($this->account, 'REQUESTED');

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/issue")->assertOk();

    $row = DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->first();
    $number = Crypt::decryptString($row->card_number);

    expect($row->status)->toBe('ACTIVE')
        ->and($number)->toMatch('/^\d{16}$/')
        ->and(CardNumber::isLuhnValid($number))->toBeTrue()
        ->and($row->card_number)->not->toContain($number)
        ->and($row->last4)->toBe(substr($number, -4))
        ->and($row->card_number_hash)->toBe(CardNumber::hash($number))
        ->and($row->issued_date)->toBe(now()->toDateString())
        ->and($row->expiry_date)->toBe(now()->addYearsNoOverflow(3)->endOfMonth()->toDateString())
        ->and($row->decided_by)->toBe($this->staff->employee_id);

    $audit = DB::table('audit_log')->where('action_type', 'CARD_ISSUED')->sole();
    expect($audit->details)->not->toContain($number)
        ->and(json_decode($audit->details, true))->toMatchArray(['last4' => $row->last4, 'after' => ['status' => 'ACTIVE', 'expiry_date' => $row->expiry_date]]);

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/issue")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a requested card can be issued.');
});

it('rejects a request with a reason the customer can see', function () {
    $card = cardFor($this->account, 'REQUESTED');

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/reject", [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/reject", ['reason' => 'Signature mismatch'])->assertOk();

    expect(cardStatus($card))->toBe('REJECTED')
        ->and(DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->value('rejection_reason'))->toBe('Signature mismatch');
    $audit = DB::table('audit_log')->where('action_type', 'CARD_REJECTED')->sole();
    expect(json_decode($audit->details, true))->toMatchArray(['reason' => 'Signature mismatch', 'after' => ['status' => 'REJECTED']]);
});

it('unblocks a blocked card', function () {
    $card = cardFor($this->account, 'BLOCKED');

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/unblock")->assertOk();

    expect(cardStatus($card))->toBe('ACTIVE');
    $this->assertDatabaseHas('audit_log', ['action_type' => 'CARD_UNBLOCKED', 'record_id' => $card->bank_card_id, 'user_id' => $this->staff->user_id]);

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/unblock")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a blocked card can be unblocked.');
});

it('answers every action on another branch\'s card with 404 and changes nothing', function (string $action, string $status) {
    $card = cardFor($this->otherBranchAccount, $status);

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/{$action}", ['reason' => 'x'])
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'Card not found.']);

    expect(cardStatus($card))->toBe($status);
    $this->assertDatabaseMissing('audit_log', ['table_affected' => 'bank_card']);
})->with([
    'issue' => ['issue', 'REQUESTED'],
    'reject' => ['reject', 'REQUESTED'],
    'unblock' => ['unblock', 'BLOCKED'],
]);

it('refuses to issue when the account was frozen after the request', function () {
    $card = cardFor($this->account, 'REQUESTED');
    DB::table('account')->where('account_id', $this->account->account_id)->update(['status' => 'FROZEN']);

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/issue")
        ->assertUnprocessable()
        ->assertJsonPath('message', "The account is not active, so the card can't be issued.");

    expect(cardStatus($card))->toBe('REQUESTED');
});

it('refuses to issue when the customer\'s login is blocked', function () {
    $card = cardFor($this->account, 'REQUESTED');
    DB::table('users')->where('user_id', $this->customer->user_id)->update(['status' => 'BLOCKED']);

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/issue")
        ->assertUnprocessable()
        ->assertJsonPath('message', "The customer's login is not active, so the card can't be issued.");
});

it('refuses to unblock an active card', function () {
    $card = cardFor($this->account, 'ACTIVE');

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/unblock")->assertStatus(409);
});

it('refuses to unblock the old card once a replacement is active', function () {
    $old = cardFor($this->account, 'BLOCKED');
    cardFor($this->account, 'ACTIVE', ['card_type_id' => cardType('Debit Gold')->card_type_id]);

    $this->postJson("/api/v1/staff/cards/{$old->bank_card_id}/unblock")
        ->assertStatus(409)
        ->assertJsonPath('message', 'A replacement card is already active on this account.');

    expect(cardStatus($old))->toBe('BLOCKED');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_UNBLOCKED']);
});

it('refuses to issue a replacement after the old card was unblocked', function () {
    $old = cardFor($this->account, 'BLOCKED');
    $replacement = cardFor($this->account, 'REQUESTED');

    $this->postJson("/api/v1/staff/cards/{$old->bank_card_id}/unblock")->assertOk();

    $this->postJson("/api/v1/staff/cards/{$replacement->bank_card_id}/issue")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This account already has an active card.');

    expect(cardStatus($replacement))->toBe('REQUESTED');
});

it('shows a customer\'s cards, masked, on the customer page', function () {
    $card = cardFor($this->account, 'ACTIVE');

    $this->getJson("/api/v1/staff/customers/{$this->customer->customer_id}")
        ->assertOk()
        ->assertJsonPath('data.cards.0.masked_number', "**** **** **** {$card->last4}")
        ->assertJsonMissingPath('data.cards.0.card_number');
});

it('keeps customers out of the staff card routes', function () {
    $card = cardFor($this->account, 'REQUESTED');
    Auth::forgetGuards();
    $this->actingAs(User::factory()->create(['customer_id' => Customer::factory()->create()->customer_id]));

    $this->postJson("/api/v1/staff/cards/{$card->bank_card_id}/issue")->assertForbidden();
    expect(cardStatus($card))->toBe('REQUESTED');
});
