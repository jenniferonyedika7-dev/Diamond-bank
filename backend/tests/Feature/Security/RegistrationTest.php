<?php

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| The public sign-up routes: limited per IP, and blind to any field that
| would grant a role, a status or a KYC result.
*/

function registerCustomer(int $n, array $extra = [], string $ip = '10.0.0.1'): TestResponse
{
    $branchId = Branch::query()->value('branch_id') ?? Branch::factory()->create()->branch_id;

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register/customer', [
        'first_name' => 'Awa', 'last_name' => "Jallow{$n}", 'date_of_birth' => '1995-04-12', 'gender' => 'F',
        'national_id' => "GM-REG-{$n}", 'phone' => '7001234', 'email' => "awa{$n}@example.test",
        'branch_id' => $branchId, 'user_name' => "awa{$n}", 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ...$extra,
    ]);
}

function registerStaff(int $n, array $extra = [], string $ip = '10.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register/staff', [
        'full_name' => "Lamin Ceesay {$n}", 'national_id' => "GM-STAFF-{$n}", 'position' => 'Teller', 'phone' => '7005555',
        'email' => "lamin{$n}@diamondbank.test", 'user_name' => "lamin{$n}", 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ...$extra,
    ]);
}

it('limits registrations to 5 a minute per IP, across both sign-up forms', function () {
    foreach (range(1, 3) as $n) {
        registerCustomer($n)->assertCreated();
    }
    registerStaff(4)->assertCreated();
    registerStaff(5)->assertCreated();

    registerCustomer(6)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many attempts. Try again in '));
    registerStaff(7)->assertStatus(429);
    $this->assertDatabaseMissing('users', ['user_name' => 'awa6']);

    // Another IP is unaffected, and the limit lifts after a minute.
    registerCustomer(8, ip: '10.0.0.2')->assertCreated();
    $this->travel(61)->seconds();
    registerCustomer(9)->assertCreated();
});

it('ignores role, status, KYC and password-change fields sent with a registration', function () {
    $adminRole = DB::table('role')->where('role_name', 'admin')->value('role_id');
    $sneaky = ['role_id' => $adminRole, 'status' => 'ACTIVE', 'kyc_status' => 'VERIFIED', 'must_change_password' => true,
        'customer_id' => 1, 'employee_id' => 1, 'verified_by' => 1];

    registerCustomer(1, $sneaky)->assertCreated();
    registerStaff(2, $sneaky)->assertCreated();

    $customer = DB::table('users')->join('role', 'role.role_id', '=', 'users.role_id')->where('user_name', 'awa1')->first();
    expect($customer->role_name)->toBe('customer')
        ->and((bool) $customer->must_change_password)->toBeFalse()
        ->and(DB::table('customer')->where('customer_id', $customer->customer_id)->first())
        ->kyc_status->toBe('PENDING')
        ->verified_by->toBeNull();

    $staff = DB::table('users')->join('role', 'role.role_id', '=', 'users.role_id')->where('user_name', 'lamin2')->first();
    expect($staff->role_name)->toBe('staff')
        ->and($staff->status)->toBe('PENDING')
        ->and($staff->customer_id)->toBeNull();
});
