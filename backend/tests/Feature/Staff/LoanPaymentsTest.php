<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Cash loan payments at the branch. The loan is 10,000 at 12% over 12 months,
 * MONTHLY, disbursed today: 888.49 a month, or 10,100.00 to pay everything now.
 */
beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->staff = staffAtBranch($this->branch);
    $this->customer = bankCustomer(['branch_id' => $this->branch->branch_id]);
    $this->account = accountFor($this->customer, '1500.00');
    $this->loan = activeLoanFor($this->customer, $this->account);

    $otherBranch = Branch::factory()->create();
    $outsider = bankCustomer(['branch_id' => $otherBranch->branch_id]);
    $this->outsideLoan = activeLoanFor($outsider, accountFor($outsider, '100.00'));

    $this->actingAs($this->staff);
});

function recordCash(object $loan, array $overrides = [])
{
    return test()->postJson("/api/v1/staff/loans/{$loan->loan_id}/payments", [
        'payment_type' => 'INSTALMENT', 'instalment_number' => 1, 'amount' => '888.49', ...$overrides,
    ]);
}

it('quotes both options for the cash payment form', function () {
    $this->getJson("/api/v1/staff/loans/{$this->loan->loan_id}/repayment")
        ->assertOk()
        ->assertJsonPath('data.next_instalment.amount', '888.49')
        ->assertJsonPath('data.payoff.amount', '10100.00')
        ->assertJsonPath('data.payoff.interest_waived', '561.86')
        ->assertJsonMissingPath('data.accounts');
});

it('records a cash instalment: no account touched, received by me', function () {
    recordCash($this->loan)
        ->assertCreated()
        ->assertJsonPath('message', 'Cash payment of D 888.49 recorded.')
        ->assertJsonPath('data.transaction_id', null)
        ->assertJsonPath('data.account_number', null)
        ->assertJsonPath('data.loan_status', 'ACTIVE');

    expect(balanceOfAccount($this->account))->toBe('1500.00')
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('loan_payment')->sole())->toMatchObject([
            'channel' => 'BRANCH', 'branch_id' => $this->branch->branch_id, 'received_by' => $this->staff->employee_id, 'paid_by' => null,
        ]);

    $this->getJson("/api/v1/staff/loans/{$this->loan->loan_id}")
        ->assertOk()
        ->assertJsonPath('data.payments.0.channel', 'BRANCH')
        ->assertJsonPath('data.payments.0.received_by.employee_id', $this->staff->employee_id)
        ->assertJsonPath('data.payments.0.received_by.name', DB::table('employee')->where('employee_id', $this->staff->employee_id)->value('full_name'))
        ->assertJsonPath('data.payments.0.branch_name', $this->branch->branch_name)
        ->assertJsonPath('data.schedule.0.status', 'PAID');
});

it('records a cash payoff and closes the loan', function () {
    recordCash($this->loan, ['payment_type' => 'EARLY_PAYOFF', 'instalment_number' => null, 'amount' => '10100.00'])
        ->assertCreated()
        ->assertJsonPath('message', 'Cash payment of D 10,100.00 recorded. The loan is now paid off.')
        ->assertJsonPath('data.loan_status', 'CLOSED');

    expect(loanRow($this->loan)->status)->toBe('CLOSED');
});

it('answers 404 for another branch\'s loan', function () {
    $this->getJson("/api/v1/staff/loans/{$this->outsideLoan->loan_id}/repayment")->assertNotFound();
    recordCash($this->outsideLoan)->assertNotFound();

    expect(DB::table('loan_payment')->count())->toBe(0);
});

it('answers 409 for a loan that is not active, and for a changed amount', function () {
    $pending = loanFor($this->customer, $this->account);

    $this->getJson("/api/v1/staff/loans/{$pending->loan_id}/repayment")->assertStatus(409);
    recordCash($pending)->assertStatus(409)->assertJsonPath('message', 'This loan is not active.');
    recordCash($this->loan, ['amount' => '888.50'])->assertStatus(409)->assertJsonPath('message', 'The amount has changed. Please review it again.');

    expect(DB::table('loan_payment')->count())->toBe(0);
});

it('lets an admin working at this branch record cash, like a deposit', function () {
    $admin = User::factory()->admin()->create();
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $admin->employee_id, 'branch_id' => $this->branch->branch_id, 'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    $this->actingAs($admin);

    recordCash($this->loan)->assertCreated();

    expect(DB::table('loan_payment')->value('received_by'))->toBe($admin->employee_id);
});
