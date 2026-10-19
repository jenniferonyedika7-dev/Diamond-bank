<?php

use App\Models\Branch;
use App\Models\User;
use App\Rules\GambianMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Every endpoint that takes a customer, staff or guarantor phone uses
| GambianMobile: 9 digits starting 83, 86 or 87, spaces removed before
| validating and saving. Branch and employer phones (often landlines) keep
| their own, looser rules.
*/

/** Sends $phone to $endpoint; the error key is "phone" except for the guarantor. */
function submitPhone(string $endpoint, string $phone): TestResponse
{
    static $n = 0;
    $n++;

    // A different IP per request keeps the registration throttle (5 a minute per IP) out of the way.
    $ip = "10.1.0.{$n}";

    return match ($endpoint) {
        'register customer' => test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register/customer', [
            'first_name' => 'Awa', 'last_name' => 'Jallow', 'date_of_birth' => '1995-04-12', 'gender' => 'F',
            'national_id' => "GM-PHONE-{$n}", 'phone' => $phone, 'email' => "awa{$n}@example.test",
            'branch_id' => Branch::factory()->create()->branch_id, 'user_name' => "awa{$n}",
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]),
        'register staff' => test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register/staff', [
            'full_name' => 'Lamin Ceesay', 'national_id' => "GM-STAFF-PHONE-{$n}", 'position' => 'Teller', 'phone' => $phone,
            'email' => "lamin{$n}@diamondbank.test", 'user_name' => "lamin{$n}",
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]),
        'staff customer edit' => (function () use ($phone) {
            $staff = staffAtBranch();
            $customer = customerWithLogin('PENDING', currentBranchId($staff));

            return test()->actingAs($staff)->putJson("/api/v1/staff/customers/{$customer->customer_id}", [
                'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
                'date_of_birth' => '1990-02-03', 'gender' => 'F', 'national_id' => $customer->national_id,
                'phone' => $phone, 'email' => $customer->email, 'branch_id' => $customer->branch_id,
            ]);
        })(),
        'loan guarantor' => (function () use ($phone) {
            $me = bankCustomer(['phone' => '830000001']);
            $account = accountFor($me, '1500.00');

            return test()->actingAs($me)->postJson('/api/v1/customer/loans', loanApplication($account, ['guarantor' => ['phone' => $phone]]));
        })(),
    };
}

/** The phone the endpoint saved, read straight from its table. */
function storedPhone(string $endpoint): ?string
{
    return match ($endpoint) {
        'register customer', 'staff customer edit' => DB::table('customer')->latest('customer_id')->value('phone'),
        'register staff' => DB::table('employee')->latest('employee_id')->value('phone'),
        'loan guarantor' => DB::table('loan_guarantor')->latest('loan_id')->value('phone'),
    };
}

dataset('phone endpoints', ['register customer', 'register staff', 'staff customer edit', 'loan guarantor']);

it('accepts a mobile number for each prefix and saves it without spaces', function (string $endpoint, string $phone, string $saved) {
    submitPhone($endpoint, $phone)->assertSuccessful();

    expect(storedPhone($endpoint))->toBe($saved);
})->with('phone endpoints')->with([
    'QCell 83' => ['831234567', '831234567'],
    'Comium 86' => ['861234567', '861234567'],
    'Africell 87' => ['871234567', '871234567'],
    'typed with spaces' => ['87 123 4567', '871234567'],
]);

it('refuses anything else with the Gambian mobile message', function (string $endpoint, string $phone) {
    $key = $endpoint === 'loan guarantor' ? 'guarantor.phone' : 'phone';

    submitPhone($endpoint, $phone)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => GambianMobile::MESSAGE]);
})->with('phone endpoints')->with([
    '8 digits' => ['87123456'],
    '10 digits' => ['8712345678'],
    'prefix 84' => ['841234567'],
    'old 7-digit number' => ['7012345'],
    'letters' => ['87abc4567'],
]);

it('keeps the landline formats for employer and branch phones', function () {
    $me = bankCustomer(['phone' => '830000001']);
    $account = accountFor($me, '1500.00');
    $this->actingAs($me)->postJson('/api/v1/customer/loans', loanApplication($account, ['employer_phone' => '+220 4227266']))
        ->assertSuccessful();

    $this->actingAs(User::factory()->admin()->create());
    $this->putJson('/api/v1/admin/bank', ['bank_name' => 'Diamond Bank', 'swift_code' => 'DBNKGMGM', 'established_date' => '2001-05-10'])
        ->assertSuccessful();
    $this->postJson('/api/v1/admin/branches', [
        'branch_name' => 'Banjul Main', 'branch_code' => 'BJL001', 'address' => '1 Independence Drive, Banjul',
        'phone' => '4220000', 'opened_date' => '2001-06-01',
    ])->assertSuccessful();
});
