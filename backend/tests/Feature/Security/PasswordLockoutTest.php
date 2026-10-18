<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| The in-app password re-checks (transfers, card reveal, loan payments and
| change-password) share one wrong-password count per user. 5 wrong -> locked
| for bank.password_lockout.first_minutes; after that, 1 wrong -> locked for
| second_minutes; after that, 1 wrong -> the login is BLOCKED. A correct
| password clears the count. Successful actions are never limited, apart from
| a 60-a-minute ceiling against scripts.
*/

beforeEach(function () {
    $this->me = bankCustomer();
    $this->other = bankCustomer();
    $this->from = accountFor($this->me, '5000.00');   // Savings, minimum 500
    $this->to = accountFor($this->other, '100.00');
    $this->actingAs($this->me);
});

function lockoutTransfer(string $password = 'password1'): TestResponse
{
    return test()->postJson('/api/v1/customer/transfers', [
        'from_account_number' => test()->from->account_number,
        'to_account_number' => test()->to->account_number,
        'amount' => '20.00',
        'password' => $password,
    ]);
}

function wrongTransfers(int $times): void
{
    foreach (range(1, $times) as $i) {
        lockoutTransfer('wrong-password')->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The password is incorrect.']);
    }
}

function assertLocked(TestResponse $response, int $minutes): void
{
    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJson(['success' => false, 'message' => "Too many wrong passwords. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.']);
}

function lockoutAudit(): array
{
    return DB::table('audit_log')->whereIn('action_type', ['PASSWORD_LOCKOUT', 'PASSWORD_LOCKOUT_BLOCKED'])->orderBy('audit_log_id')
        ->get(['action_type', 'details'])->map(fn ($row) => [$row->action_type, json_decode($row->details, true)])->all();
}

it('never limits successful transfers', function () {
    foreach (range(1, 10) as $i) {
        lockoutTransfer()->assertCreated();
    }

    expect(balanceOfAccount($this->from))->toBe('4800.00');
});

it('clears the count on a correct password', function () {
    wrongTransfers(4);
    lockoutTransfer()->assertCreated();

    // Counting from zero again: 4 more wrong passwords don't lock, the 5th does.
    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 1);
});

it('locks after 5 wrong passwords, refusing even the correct one', function () {
    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 1);

    assertLocked(lockoutTransfer(), 1);
    expect(balanceOfAccount($this->from))->toBe('5000.00')
        ->and(DB::table('transfer')->count())->toBe(0)
        ->and(lockoutAudit())->toBe([['PASSWORD_LOCKOUT', ['stage' => 1, 'minutes' => 1]]]);
});

it('locks for 2 minutes on the first wrong password after the first lock', function () {
    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 1);

    $this->travel(61)->seconds();
    assertLocked(lockoutTransfer('wrong-password'), 2);

    $this->travel(1)->minutes();
    assertLocked(lockoutTransfer(), 1);

    $this->travel(61)->seconds();
    lockoutTransfer()->assertCreated();
    expect(lockoutAudit())->toBe([
        ['PASSWORD_LOCKOUT', ['stage' => 1, 'minutes' => 1]],
        ['PASSWORD_LOCKOUT', ['stage' => 2, 'minutes' => 2]],
    ]);
});

it('blocks the login on the first wrong password after the second lock', function () {
    DB::table('sessions')->insert([
        'id' => 'other-session', 'user_id' => $this->me->user_id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);
    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 1);
    $this->travel(61)->seconds();
    assertLocked(lockoutTransfer('wrong-password'), 2);
    $this->travel(121)->seconds();

    lockoutTransfer('wrong-password')
        ->assertForbidden()
        ->assertJson(['success' => false, 'message' => 'Your account is locked. Please contact your branch.', 'data' => ['account_locked' => true]]);

    expect($this->me->fresh()->status)->toBe('BLOCKED')
        ->and(DB::table('sessions')->where('user_id', $this->me->user_id)->exists())->toBeFalse()
        ->and(lockoutAudit())->toBe([
            ['PASSWORD_LOCKOUT', ['stage' => 1, 'minutes' => 1]],
            ['PASSWORD_LOCKOUT', ['stage' => 2, 'minutes' => 2]],
            ['PASSWORD_LOCKOUT_BLOCKED', ['stage' => 3]],
        ]);
    expect(balanceOfAccount($this->from))->toBe('5000.00');

    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/login', ['user_name' => $this->me->user_name, 'password' => 'password1'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your account is locked. Please contact your branch.');
});

it('keeps the usual message for a login blocked by staff', function () {
    $staff = staffAtBranch(Branch::find($this->me->customer->branch_id));
    $this->actingAs($staff);
    $this->postJson("/api/v1/staff/customers/{$this->me->customer_id}/block", ['reason' => 'Fraud'])->assertOk();

    Auth::forgetGuards();
    $this->postJson('/api/v1/auth/login', ['user_name' => $this->me->user_name, 'password' => 'password1'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your account is blocked. Please contact the bank.');
});

it('starts from zero after a staff unblock', function () {
    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 1);
    $this->travel(61)->seconds();
    assertLocked(lockoutTransfer('wrong-password'), 2);
    $this->travel(121)->seconds();
    lockoutTransfer('wrong-password')->assertForbidden();

    DB::table('users')->where('user_id', $this->me->user_id)->update(['status' => 'ACTIVE']);
    Auth::forgetGuards();
    $this->actingAs($this->me->fresh());

    wrongTransfers(4);
    lockoutTransfer()->assertCreated();
});

it('counts wrong passwords on card reveals and loan payments toward the same budget', function () {
    $card = cardFor($this->from, 'ACTIVE');
    $loan = activeLoanFor($this->me, $this->from);
    $pay = fn (string $password) => $this->postJson("/api/v1/customer/loans/{$loan->loan_id}/payments", [
        'payment_type' => 'INSTALMENT', 'instalment_number' => 1, 'amount' => '888.49',
        'account_number' => $this->from->account_number, 'password' => $password,
    ]);
    $reveal = fn (string $password) => $this->postJson("/api/v1/customer/cards/{$card->bank_card_id}/reveal", ['password' => $password]);

    wrongTransfers(2);
    $reveal('wrong-password')->assertUnprocessable();
    $pay('wrong-password')->assertUnprocessable();
    assertLocked($reveal('wrong-password'), 1);

    assertLocked($pay('password1'), 1);
    assertLocked($reveal('password1'), 1);
    assertLocked(lockoutTransfer(), 1);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'CARD_NUMBER_REVEALED']);
    expect(DB::table('loan_payment')->count())->toBe(0);
});

it('counts wrong current passwords on change-password, and locks it too', function () {
    $change = fn (string $current) => $this->postJson('/api/v1/auth/change-password', [
        'current_password' => $current, 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
    ]);

    foreach (range(1, 4) as $i) {
        $change('wrong-password')->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'The password is incorrect.']);
    }
    assertLocked(lockoutTransfer('wrong-password'), 1);

    assertLocked($change('password1'), 1);
    expect(DB::table('audit_log')->where('action_type', 'CHANGE_PASSWORD_FAILED')->count())->toBe(4);
    $this->assertDatabaseMissing('audit_log', ['action_type' => 'PASSWORD_CHANGED']);
});

it('takes the lock durations from config', function () {
    config(['bank.password_lockout.first_minutes' => 3]);

    wrongTransfers(4);
    assertLocked(lockoutTransfer('wrong-password'), 3);

    $this->travel(2)->minutes();
    assertLocked(lockoutTransfer(), 1);
    $this->travel(61)->seconds();
    lockoutTransfer()->assertCreated();
});

it('forgets the count and stage 24 hours after the last wrong password', function () {
    wrongTransfers(4);
    $this->travel(25)->hours();
    actingFresh($this->me);   // a new session: the old one was logged out for inactivity
    wrongTransfers(4);   // would have locked at the 5th if the count had survived

    assertLocked(lockoutTransfer('wrong-password'), 1);
    $this->travel(25)->hours();
    actingFresh($this->me);
    wrongTransfers(1);   // stage forgotten too: a plain wrong password, not the 2-minute lock
});

it('keeps a 60-a-minute ceiling against scripts', function () {
    foreach (range(1, 60) as $i) {
        $this->postJson('/api/v1/customer/transfers', [])->assertUnprocessable();
    }

    $this->postJson('/api/v1/customer/transfers', [])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many attempts. Try again in '));
});

it('blocks staff after the second lock on change-password, but never an admin', function (string $role) {
    $user = $role === 'admin'
        ? tap(User::query()->whereHas('role', fn ($q) => $q->where('role_name', 'admin'))->firstOrFail(), fn ($admin) => $admin->forceFill(['must_change_password' => false])->save())
        : User::factory()->staff()->create();
    $user->forceFill(['password' => 'password1'])->save();
    Auth::forgetGuards();
    $this->actingAs($user->fresh());
    $change = fn () => $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'wrong-password', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
    ]);

    foreach (range(1, 4) as $i) {
        $change()->assertUnprocessable();
    }
    assertLocked($change(), 1);
    $this->travel(61)->seconds();
    assertLocked($change(), 2);
    $this->travel(121)->seconds();

    if ($role === 'staff') {
        $change()->assertForbidden()->assertJsonPath('data.account_locked', true);
        expect($user->fresh()->status)->toBe('BLOCKED');
    } else {
        assertLocked($change(), 2);
        expect($user->fresh()->status)->toBe('ACTIVE')
            ->and(lockoutAudit())->toBe([
                ['PASSWORD_LOCKOUT', ['stage' => 1, 'minutes' => 1]],
                ['PASSWORD_LOCKOUT', ['stage' => 2, 'minutes' => 2]],
                ['PASSWORD_LOCKOUT', ['stage' => 2, 'minutes' => 2]],
            ]);
    }
})->with(['staff', 'admin']);
