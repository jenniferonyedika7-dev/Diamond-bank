<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
 * Online loan payments. The loan is 10,000 at 12% over 12 months, MONTHLY,
 * disbursed today: 888.49 a month; paying everything now is 10,100.00
 * (only month 1's interest, 561.86 waived).
 */
beforeEach(function () {
    $this->me = bankCustomer();
    $this->account = accountFor($this->me, '20000.00');
    $this->loan = activeLoanFor($this->me, $this->account);
    $this->actingAs($this->me);
});

function payLoan(object $loan, array $overrides = [])
{
    return test()->postJson("/api/v1/customer/loans/{$loan->loan_id}/payments", [
        'payment_type' => 'INSTALMENT',
        'instalment_number' => 1,
        'amount' => '888.49',
        'account_number' => test()->account->account_number,
        'password' => 'password1',
        ...$overrides,
    ]);
}

it('quotes the next instalment and paying everything now, with my accounts', function () {
    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}/repayment")
        ->assertOk()
        ->assertJsonPath('data.next_instalment.instalment_number', 1)
        ->assertJsonPath('data.next_instalment.amount', '888.49')
        ->assertJsonPath('data.payoff.amount', '10100.00')
        ->assertJsonPath('data.payoff.interest_charged', '100.00')
        ->assertJsonPath('data.payoff.interest_waived', '561.86')
        ->assertJsonPath('data.outstanding', '10661.86')
        ->assertJsonPath('data.accounts.0.account_number', $this->account->account_number)
        ->assertJsonPath('data.accounts.0.minimum_balance', '500.00');
});

it('pays the next instalment from my account', function () {
    payLoan($this->loan)
        ->assertCreated()
        ->assertJsonPath('message', "Payment received. D 888.49 was paid from account {$this->account->account_number}.")
        ->assertJsonPath('data.balance_after', '19111.51')
        ->assertJsonPath('data.remaining_balance', '9773.37')
        ->assertJsonPath('data.loan_status', 'ACTIVE');

    expect(balanceOfAccount($this->account))->toBe('19111.51');

    $detail = $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}")->assertOk();
    $detail->assertJsonPath('data.schedule.0.status', 'PAID')
        ->assertJsonPath('data.schedule.0.amount_paid', '888.49')
        ->assertJsonPath('data.payments.0.payment_type', 'INSTALMENT')
        ->assertJsonPath('data.payments.0.channel', 'ONLINE')
        ->assertJsonPath('data.payments.0.amount', '888.49')
        ->assertJsonPath('data.payments.0.instalments', [1])
        ->assertJsonPath('data.payments.0.account_number', $this->account->account_number)
        ->assertJsonMissingPath('data.payments.0.received_by');
});

it('pays everything off and closes the loan', function () {
    payLoan($this->loan, ['payment_type' => 'EARLY_PAYOFF', 'instalment_number' => null, 'amount' => '10100.00'])
        ->assertCreated()
        ->assertJsonPath('message', "Payment received. D 10,100.00 was paid from account {$this->account->account_number}. Your loan is now paid off.")
        ->assertJsonPath('data.interest_waived', '561.86')
        ->assertJsonPath('data.loan_status', 'CLOSED');

    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}")
        ->assertJsonPath('data.status', 'CLOSED')
        ->assertJsonPath('data.can_pay', false)
        ->assertJsonPath('data.schedule.11.status', 'SETTLED');
    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}/repayment")->assertStatus(409);
});

it('answers 409 when the amount has changed, moving nothing', function () {
    payLoan($this->loan, ['amount' => '888.48'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The amount has changed. Please review it again.');

    expect(balanceOfAccount($this->account))->toBe('20000.00')
        ->and(DB::table('loan_payment')->count())->toBe(0);
});

it('answers 409 for a loan that is not active', function () {
    DB::table('loan')->where('loan_id', $this->loan->loan_id)->update(['status' => 'CLOSED', 'closed_at' => now()]);

    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}/repayment")->assertStatus(409)->assertJsonPath('message', 'This loan is not active.');
    payLoan($this->loan)->assertStatus(409)->assertJsonPath('message', 'This loan is not active.');

    expect(balanceOfAccount($this->account))->toBe('20000.00');
});

it('answers 404 for another customer\'s loan, whatever the password', function () {
    $other = bankCustomer();
    $theirLoan = activeLoanFor($other, accountFor($other, '20000.00'));

    $this->getJson("/api/v1/customer/loans/{$theirLoan->loan_id}/repayment")->assertNotFound()->assertJsonPath('message', 'Loan not found.');
    payLoan($theirLoan)->assertNotFound();
    payLoan($theirLoan, ['password' => 'wrong'])->assertNotFound();

    expect(DB::table('loan_payment')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action_type', 'LOAN_PAYMENT_PASSWORD_FAILED')->count())->toBe(0);
});

it('refuses a wrong password with 422 and audits it without the password', function () {
    payLoan($this->loan, ['password' => 'not-my-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'The password is incorrect.']);

    $audit = DB::table('audit_log')->where('action_type', 'LOAN_PAYMENT_PASSWORD_FAILED')->sole();
    // MySQL's JSON type reorders keys, so compare both sides sorted by key.
    $details = json_decode($audit->details, true);
    $expected = ['loan_id' => $this->loan->loan_id, 'payment_type' => 'INSTALMENT', 'amount' => '888.49', 'account_number' => $this->account->account_number];
    ksort($details);
    ksort($expected);

    expect($audit->user_id)->toBe($this->me->user_id)
        ->and($details)->toBe($expected)
        ->and($audit->details)->not->toContain('not-my-password')
        ->and(balanceOfAccount($this->account))->toBe('20000.00');
});

it('pays only from my own accounts', function () {
    $other = bankCustomer();

    payLoan($this->loan, ['account_number' => accountFor($other, '20000.00')->account_number])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_number' => 'Choose one of your own active accounts.']);
    payLoan($this->loan, ['account_number' => accountFor($this->me, '20000.00', ['status' => 'FROZEN'])->account_number])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This account is frozen.');

    expect(DB::table('loan_payment')->count())->toBe(0);
});

it('refuses a payment below the account\'s minimum balance', function () {
    DB::table('account')->where('account_id', $this->account->account_id)->update(['balance' => '1000.00']);

    payLoan($this->loan)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Insufficient funds: this payment would take the account below its minimum balance.');
});

it('validates the payment before touching anything', function (array $overrides, string $field, string $message) {
    payLoan($this->loan, $overrides)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

    expect(DB::table('loan_payment')->count())->toBe(0);
})->with([
    'unknown type' => [['payment_type' => 'PART'], 'payment_type', 'Choose what to pay: the next instalment or everything now.'],
    'instalment without its number' => [['instalment_number' => null], 'instalment_number', 'Say which instalment is being paid.'],
    'three decimals' => [['amount' => '888.495'], 'amount', 'Amounts can have at most 2 decimal places.'],
    'zero' => [['amount' => '0'], 'amount', 'The amount must be greater than zero.'],
]);

it('offers a SINGLE loan only the pay-everything option', function () {
    DB::table('loan_instalment')->where('loan_id', $this->loan->loan_id)->delete();
    DB::table('loan')->where('loan_id', $this->loan->loan_id)->update(['status' => 'CANCELLED']);
    $single = activeLoanFor($this->me, $this->account, ['repayment_plan' => 'SINGLE']);

    $this->getJson("/api/v1/customer/loans/{$single->loan_id}/repayment")
        ->assertOk()
        ->assertJsonPath('data.next_instalment', null)
        ->assertJsonPath('data.payoff.amount', '10100.00');
    payLoan($single, ['amount' => '11200.00'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This loan is repaid in one payment. Choose to pay everything now.');
});

it('shares one budget of password attempts with transfers and card reveals', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/customer/transfers', [])->assertUnprocessable();
    }
    foreach (range(1, 2) as $i) {
        payLoan($this->loan, ['password' => 'wrong'])->assertUnprocessable();
    }

    payLoan($this->loan)->assertStatus(429)->assertHeader('Retry-After');
    $this->postJson('/api/v1/customer/transfers', [])->assertStatus(429);

    expect(DB::table('loan_payment')->count())->toBe(0);
});

it('needs a login', function () {
    Auth::forgetGuards();
    $this->app['auth']->guard('web')->logout();

    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}/repayment")->assertUnauthorized();
    $this->postJson("/api/v1/customer/loans/{$this->loan->loan_id}/payments", [])->assertUnauthorized();
});
