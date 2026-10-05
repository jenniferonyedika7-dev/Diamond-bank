<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('lists customers with search and filters', function () {
    $banjul = Branch::factory()->create();
    $awa = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow', 'branch_id' => $banjul->branch_id]);
    accountFor($awa, '100.00');
    customerWithLogin('PENDING');

    $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonPath('data.pagination.total', 2);

    $this->getJson('/api/v1/admin/customers?search=Jallow')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.user_name', $awa->user_name)
        ->assertJsonPath('data.items.0.branch.branch_name', $banjul->branch_name)
        ->assertJsonPath('data.items.0.accounts_count', 1);

    $this->getJson('/api/v1/admin/customers?kyc_status=PENDING')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson("/api/v1/admin/customers?branch_id={$banjul->branch_id}")->assertOk()->assertJsonPath('data.pagination.total', 1);
});

it('shows a customer\'s accounts with type, balance, status and branch', function () {
    $awa = bankCustomer(['first_name' => 'Awa']);
    $savings = accountFor($awa, '1500.00');
    $otherBranch = Branch::factory()->create();
    $frozen = accountFor($awa, '20.00', ['status' => 'FROZEN', 'branch_id' => $otherBranch->branch_id]);

    $this->getJson("/api/v1/admin/customers/{$awa->customer_id}")
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Awa')
        ->assertJsonPath('data.login.user_name', $awa->user_name)
        ->assertJsonMissingPath('data.login.password')
        ->assertJsonCount(2, 'data.accounts')
        ->assertJsonPath('data.accounts.0.account_number', $savings->account_number)
        ->assertJsonPath('data.accounts.0.type_name', 'Savings')
        ->assertJsonPath('data.accounts.0.balance', '1500.00')
        ->assertJsonPath('data.accounts.0.status', 'ACTIVE')
        ->assertJsonPath('data.accounts.1.account_number', $frozen->account_number)
        ->assertJsonPath('data.accounts.1.status', 'FROZEN')
        ->assertJsonPath('data.accounts.1.branch_name', $otherBranch->branch_name);
});

it('returns 404 for a missing customer', function () {
    $this->getJson('/api/v1/admin/customers/999999')
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'The requested record was not found.']);
});

it('offers no way to change a customer or their accounts except the username', function () {
    $awa = bankCustomer();
    $account = accountFor($awa, '100.00');

    $writeRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/customers'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();
    expect($writeRoutes)->toBe(['api/v1/admin/customers/{customer}/username']);

    foreach (['put', 'patch', 'delete', 'post'] as $method) {
        expect($this->json($method, "/api/v1/admin/customers/{$awa->customer_id}", ['first_name' => 'Changed'])->status())->toBeIn([404, 405]);
    }
    expect($this->postJson("/api/v1/admin/customers/{$awa->customer_id}/accounts/{$account->account_number}/freeze")->status())->toBe(404);

    expect(DB::table('account')->where('account_id', $account->account_id)->value('status'))->toBe('ACTIVE');
});
