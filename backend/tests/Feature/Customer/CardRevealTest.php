<?php

use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->me = bankCustomer();
    $this->other = bankCustomer();
    $this->mine = accountFor($this->me, '1500.00');
    $this->theirs = accountFor($this->other, '800.00');
    $this->card = cardFor($this->mine, 'ACTIVE');
    $this->number = Crypt::decryptString($this->card->card_number);
    $this->actingAs($this->me);
});

function reveal(object $card, ?string $password = 'password1'): TestResponse
{
    return test()->postJson("/api/v1/customer/cards/{$card->bank_card_id}/reveal", $password === null ? [] : ['password' => $password]);
}

/** Every audit_log row as one string, to check a full card number never lands there. */
function auditText(): string
{
    return DB::table('audit_log')->get()->map(fn ($row) => json_encode($row))->implode("\n");
}

it('reveals the full number of my active card, uncached and audited with last4 only', function () {
    $response = reveal($this->card)
        ->assertOk()
        ->assertJsonPath('data.card_number', $this->number)
        ->assertJsonPath('data.last4', $this->card->last4);

    expect($this->number)->toMatch('/^\d{16}$/')
        ->and(CardNumber::isLuhnValid($this->number))->toBeTrue()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $audit = DB::table('audit_log')->where('action_type', 'CARD_NUMBER_REVEALED')->sole();
    expect($audit->user_id)->toBe($this->me->user_id)
        ->and($audit->record_id)->toBe($this->card->bank_card_id)
        ->and(json_decode($audit->details, true))->toEqual(['account_number' => $this->mine->account_number, 'last4' => $this->card->last4])
        ->and(auditText())->not->toContain($this->number);

    // The list stays masked.
    expect($this->getJson('/api/v1/customer/cards')->assertOk()->getContent())->not->toContain($this->number);
});

it('refuses a wrong password, audits the attempt and reveals nothing', function () {
    $response = reveal($this->card, 'wrong-pass-123')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'The password is incorrect.']);

    expect($response->getContent())->not->toContain($this->number);

    $audit = DB::table('audit_log')->where('action_type', 'CARD_REVEAL_PASSWORD_FAILED')->sole();
    expect($audit->user_id)->toBe($this->me->user_id)
        ->and($audit->table_affected)->toBe('users')
        ->and(json_decode($audit->details, true))->toEqual(['bank_card_id' => $this->card->bank_card_id, 'last4' => $this->card->last4])
        ->and(auditText())->not->toContain('wrong-pass-123');
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
});

it('requires the password', function () {
    reveal($this->card, null)->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(DB::table('audit_log')->whereIn('action_type', ['CARD_REVEAL_PASSWORD_FAILED', 'CARD_NUMBER_REVEALED'])->exists())->toBeFalse();
});

it('answers another customer\'s card with the same 404 as a missing one', function () {
    $theirs = cardFor($this->theirs, 'ACTIVE');
    $theirNumber = Crypt::decryptString($theirs->card_number);

    $response = reveal($theirs)->assertNotFound()->assertJson(['success' => false, 'message' => 'Card not found.']);
    $this->postJson('/api/v1/customer/cards/999999/reveal', ['password' => 'password1'])
        ->assertNotFound()->assertJson(['success' => false, 'message' => 'Card not found.']);

    expect($response->getContent())->not->toContain($theirNumber);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
});

it('refuses cards that are not active', function (string $status) {
    $card = cardFor(accountFor($this->me, '100.00'), $status);

    $response = reveal($card)
        ->assertStatus(409)
        ->assertJsonPath('message', "Only an active card's number can be shown.");

    if ($card->card_number !== null) {
        expect($response->getContent())->not->toContain(Crypt::decryptString($card->card_number));
    }
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
})->with(['BLOCKED', 'REQUESTED']);

it('refuses the reveal route to staff and admins', function (string $role) {
    Auth::forgetGuards();
    $this->actingAs($role === 'staff' ? staffAtBranch() : User::factory()->admin()->create());

    reveal($this->card)
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to access this resource.');

    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
})->with(['staff', 'admin']);

it('limits reveals to 5 attempts per minute', function () {
    foreach (range(1, 5) as $i) {
        reveal($this->card, 'nope')->assertUnprocessable();
    }

    reveal($this->card)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many attempts. Try again in '));

    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
});

it('shares one budget of password attempts with transfers', function () {
    $transfer = [
        'from_account_number' => $this->mine->account_number,
        'to_account_number' => $this->theirs->account_number,
        'amount' => '10.00',
        'password' => 'nope',
    ];
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/customer/transfers', $transfer)->assertUnprocessable();
    }
    foreach (range(1, 2) as $i) {
        reveal($this->card, 'nope')->assertUnprocessable();
    }

    reveal($this->card)->assertStatus(429);
});
