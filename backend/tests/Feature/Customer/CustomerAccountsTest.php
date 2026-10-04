<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->me = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow']);
    $this->other = bankCustomer();
    $this->mine = accountFor($this->me, '1500.00');
    $this->theirs = accountFor($this->other, '800.00');
    $this->actingAs($this->me);
});

it('shows only my accounts, totals and recent transactions on the overview', function () {
    $this->getJson('/api/v1/customer/overview')
        ->assertOk()
        ->assertJsonPath('data.customer.first_name', 'Awa')
        ->assertJsonPath('data.customer.kyc_status', 'VERIFIED')
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.account_number', $this->mine->account_number)
        ->assertJsonPath('data.accounts.0.minimum_balance', '500.00')
        ->assertJsonPath('data.totals.0.currency_code', 'GMD')
        ->assertJsonPath('data.totals.0.balance', '1500.00')
        ->assertJsonMissing(['account_number' => $this->theirs->account_number]);
});

it('returns the same 404 for another customer\'s account as for a missing one', function (string $path) {
    $theirs = $this->getJson(sprintf($path, $this->theirs->account_number))->assertNotFound();
    $missing = $this->getJson(sprintf($path, 'DB0019999999'))->assertNotFound();

    expect($theirs->json())->toBe($missing->json())
        ->and($theirs->json('message'))->toBe('Account not found.');
})->with([
    'details' => ['/api/v1/customer/accounts/%s'],
    'transactions' => ['/api/v1/customer/accounts/%s/transactions'],
]);

it('shows my account with its minimum balance, and filters my transactions', function () {
    $this->getJson("/api/v1/customer/accounts/{$this->mine->account_number}")
        ->assertOk()
        ->assertJsonPath('data.balance', '1500.00')
        ->assertJsonPath('data.account_type.minimum_balance', '500.00')
        ->assertJsonMissingPath('data.customer_id')
        ->assertJsonMissingPath('data.account_id');

    $typeId = fn (string $name) => DB::table('transaction_type')->where('type_name', $name)->value('transaction_type_id');
    DB::table('transactions')->insert([
        ['account_id' => $this->mine->account_id, 'transaction_type_id' => $typeId('DEPOSIT'), 'amount' => 1500, 'channel' => 'BRANCH', 'balance_after' => 1500, 'transaction_date' => '2026-01-05 10:00:00'],
        ['account_id' => $this->mine->account_id, 'transaction_type_id' => $typeId('WITHDRAWAL'), 'amount' => 100, 'channel' => 'BRANCH', 'balance_after' => 1400, 'transaction_date' => '2026-02-05 10:00:00'],
    ]);

    $url = "/api/v1/customer/accounts/{$this->mine->account_number}/transactions";
    $this->getJson($url)->assertJsonPath('data.pagination.total', 2)->assertJsonPath('data.items.0.type_name', 'WITHDRAWAL');
    $this->getJson("{$url}?type=DEPOSIT")->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.type_name', 'DEPOSIT');
    $this->getJson("{$url}?from=2026-02-01&to=2026-02-28")->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.type_name', 'WITHDRAWAL');
    $this->getJson("{$url}?type=NOT_A_TYPE")->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('shows a read-only profile', function () {
    $this->getJson('/api/v1/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Awa')
        ->assertJsonPath('data.kyc_status', 'VERIFIED');

    $this->putJson('/api/v1/customer/profile', ['first_name' => 'Changed'])->assertStatus(405);
});

it('refuses every customer route to staff and admins', function (string $role) {
    $user = $role === 'staff' ? staffAtBranch() : User::factory()->admin()->create();
    Auth::forgetGuards();
    $this->actingAs($user);

    $routes = customerRoutes();
    expect(count($routes))->toBeGreaterThanOrEqual(6);

    foreach ($routes as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'You do not have permission to access this resource.']);
    }
})->with(['staff', 'admin']);

it('keeps customers out of staff and admin routes', function () {
    $this->getJson('/api/v1/staff/customers')->assertForbidden();
    $this->getJson('/api/v1/staff/audit-log')->assertForbidden();
    $this->getJson('/api/v1/admin/overview')->assertForbidden();
    $this->postJson("/api/v1/staff/accounts/{$this->mine->account_number}/deposit", ['amount' => 10])->assertForbidden();

    expect(balanceOfAccount($this->mine))->toBe('1500.00');
});
