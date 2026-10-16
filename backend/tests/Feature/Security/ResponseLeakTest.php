<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| No response carries a password hash, a remember token or a full card number
| (or its hash). Only the password-checked card reveal returns the number.
*/

beforeEach(function () {
    $this->customer = bankCustomer(['first_name' => 'Awa']);
    DB::table('users')->where('user_id', $this->customer->user_id)->update(['remember_token' => 'remember-me-token-123']);
    $this->account = accountFor($this->customer, '1500.00');
    $this->card = cardFor($this->account, 'ACTIVE');
    $this->secrets = [
        DB::table('users')->where('user_id', $this->customer->user_id)->value('password'),
        'remember-me-token-123',
        Crypt::decryptString($this->card->card_number),
        $this->card->card_number,
        $this->card->card_number_hash,
    ];
});

function assertNoSecrets(TestResponse $response, array $secrets): void
{
    $body = $response->assertOk()->getContent();

    foreach (['"password"', '"remember_token"', '"card_number"', '"card_number_hash"'] as $key) {
        expect($body)->not->toContain($key);
    }
    foreach ($secrets as $secret) {
        expect($body)->not->toContain(str_replace('/', '\\/', $secret))->not->toContain($secret);
    }
}

it('keeps secrets out of login and /auth/me', function () {
    assertNoSecrets($this->postJson('/api/v1/auth/login', ['user_name' => $this->customer->user_name, 'password' => 'password1']), $this->secrets);
    assertNoSecrets($this->getJson('/api/v1/auth/me'), $this->secrets);
});

it('keeps secrets out of the customer\'s own screens', function () {
    $this->actingAs($this->customer);

    foreach (['/api/v1/customer/overview', '/api/v1/customer/profile', '/api/v1/customer/cards',
        "/api/v1/customer/accounts/{$this->account->account_number}"] as $uri) {
        assertNoSecrets($this->getJson($uri), $this->secrets);
    }
});

it('keeps secrets out of the staff customer screens', function () {
    $this->actingAs(staffAtBranch(Branch::find($this->account->branch_id)));

    assertNoSecrets($this->getJson("/api/v1/staff/customers/{$this->customer->customer_id}"), $this->secrets);
    assertNoSecrets($this->getJson('/api/v1/staff/customers'), $this->secrets);
    assertNoSecrets($this->getJson('/api/v1/staff/cards?status=ACTIVE')->assertJsonCount(1, 'data.items'), $this->secrets);
});

it('keeps secrets out of the admin customer and staff screens', function () {
    $admin = User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail();
    $admin->forceFill(['must_change_password' => false])->save();
    $staff = User::factory()->staff()->create(['remember_token' => 'staff-token-456']);
    $this->actingAs($admin->fresh());

    $secrets = [...$this->secrets, $staff->password, 'staff-token-456'];
    assertNoSecrets($this->getJson("/api/v1/admin/customers/{$this->customer->customer_id}"), $secrets);
    assertNoSecrets($this->getJson('/api/v1/admin/customers'), $secrets);
    assertNoSecrets($this->getJson('/api/v1/admin/staff'), $secrets);
});
