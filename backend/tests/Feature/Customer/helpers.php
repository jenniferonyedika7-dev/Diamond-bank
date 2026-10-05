<?php

use App\Models\Customer;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** A VERIFIED customer with a login (password "password1", from UserFactory). */
function bankCustomer(array $attributes = []): User
{
    $customer = Customer::factory()->create(['kyc_status' => 'VERIFIED', ...$attributes]);

    return User::factory()->create(['customer_id' => $customer->customer_id]);
}

/** Inserts an account directly (test setup only; the app moves money through the procedures). */
function accountFor(User $user, string $balance, array $overrides = []): object
{
    static $n = 0;
    $n++;

    $typeId = DB::table('account_type')->where('type_name', 'Savings')->value('account_type_id')
        ?? DB::table('account_type')->insertGetId(['type_name' => 'Savings', 'interest_rate' => 3.5, 'minimum_balance' => 500], 'account_type_id');

    $id = DB::table('account')->insertGetId(array_merge([
        'customer_id' => $user->customer_id,
        'branch_id' => DB::table('customer')->where('customer_id', $user->customer_id)->value('branch_id'),
        'account_type_id' => $typeId,
        'account_number' => 'DB001'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        'balance' => $balance,
    ], $overrides), 'account_id');

    return DB::table('account')->where('account_id', $id)->first();
}

function balanceOfAccount(object $account): string
{
    return DB::table('account')->where('account_id', $account->account_id)->value('balance');
}

/** Every registered /api/v1/customer route as [method, uri], with parameters filled in. */
function customerRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/customer'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, '/'.preg_replace(['/\{cardId\}/', '/\{[^}]+\}/'], ['999999', 'DB0019999999'], $route->uri())]))
        ->values()
        ->all();
}

function cardType(string $name = 'Debit Classic'): object
{
    $id = DB::table('card_type')->where('type_name', $name)->value('card_type_id')
        ?? DB::table('card_type')->insertGetId(['type_name' => $name, 'daily_limit' => 10000], 'card_type_id');

    return DB::table('card_type')->where('card_type_id', $id)->first();
}

/** Inserts a card directly (test setup). Issued statuses get a real encrypted number, hash and dates. */
function cardFor(object $account, string $status = 'REQUESTED', array $overrides = []): object
{
    $issued = ! in_array($status, ['REQUESTED', 'REJECTED'], true);
    $number = $issued ? CardNumber::generateUnique() : null;

    $id = DB::table('bank_card')->insertGetId(array_merge([
        'account_id' => $account->account_id,
        'card_type_id' => cardType()->card_type_id,
        'status' => $status,
        'card_number' => $number === null ? null : Crypt::encryptString($number),
        'card_number_hash' => $number === null ? null : CardNumber::hash($number),
        'last4' => $number === null ? null : substr($number, -4),
        'issued_date' => $issued ? now()->toDateString() : null,
        'expiry_date' => $issued ? now()->addYears(3)->endOfMonth()->toDateString() : null,
        'requested_at' => now(),
    ], $overrides), 'bank_card_id');

    return DB::table('bank_card')->where('bank_card_id', $id)->first();
}
