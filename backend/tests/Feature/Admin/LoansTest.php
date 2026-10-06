<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->staff = staffAtBranch($this->branch);
    $this->customer = bankCustomer(['branch_id' => $this->branch->branch_id]);
    $this->account = accountFor($this->customer, '1500.00');
    $this->loan = awaitingAdminLoan($this->customer, $this->account, $this->staff);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('lists loans awaiting admin approval at every branch', function () {
    $elsewhere = bankCustomer();
    awaitingAdminLoan($elsewhere, accountFor($elsewhere, '10.00'), staffAtBranch());
    $pending = bankCustomer();
    loanFor($pending, accountFor($pending, '10.00'));

    $this->getJson('/api/v1/admin/loans')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.counts.AWAITING_ADMIN', 2)
        ->assertJsonPath('data.counts.PENDING', 1);

    $this->getJson("/api/v1/admin/loans?branch_id={$this->branch->branch_id}")->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/v1/admin/loans?status=PENDING')->assertJsonPath('data.pagination.total', 1);
});

it('shows the same detail and statement as staff', function () {
    recordChecks($this->loan, $this->staff);

    $this->getJson("/api/v1/admin/loans/{$this->loan->loan_id}")
        ->assertOk()
        ->assertJsonPath('data.tin', '123456789')
        ->assertJsonPath('data.staff_approval.employee_id', $this->staff->employee_id)
        ->assertJsonPath('data.verifications.3.recorded', true)
        ->assertJsonPath('data.affordability.above_warning', false);

    $this->getJson("/api/v1/admin/loans/{$this->loan->loan_id}/statement")->assertOk()->assertJsonPath('data.months', 6);
    $this->getJson('/api/v1/admin/loans/999999')->assertNotFound();
});

it('approves and disburses: balance, transaction, schedule and both approvals recorded', function () {
    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")
        ->assertOk()
        ->assertJsonPath('message', "Loan approved. D 10,000.00 was paid into account {$this->account->account_number}.")
        ->assertJsonPath('data.balance_after', '11500.00');

    expect(balanceOfAccount($this->account))->toBe('11500.00')
        ->and(DB::table('loan_instalment')->where('loan_id', $this->loan->loan_id)->count())->toBe(12)
        ->and(loanRow($this->loan))->toMatchObject([
            'status' => 'ACTIVE', 'staff_approved_by' => $this->staff->employee_id, 'approved_by' => $this->admin->employee_id,
        ]);

    $transaction = DB::table('transactions')->sole();
    expect($transaction->transaction_type_id)->toBe(DB::table('transaction_type')->where('type_name', 'LOAN_DISBURSEMENT')->value('transaction_type_id'))
        ->and($transaction->amount)->toBe('10000.00');

    $this->getJson("/api/v1/admin/loans/{$this->loan->loan_id}")
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonPath('data.admin_approval.employee_id', $this->admin->employee_id)
        ->assertJsonPath('data.schedule.0.display_status', 'DUE')
        ->assertJsonPath('data.disbursement_transaction_id', $transaction->transaction_id);
});

it('approves a single-payment loan', function () {
    $borrower = bankCustomer();
    $single = awaitingAdminLoan($borrower, accountFor($borrower, '0.00'), $this->staff, ['repayment_plan' => 'SINGLE', 'loan_term_months' => 3]);

    $this->postJson("/api/v1/admin/loans/{$single->loan_id}/approve")->assertOk();

    expect(DB::table('loan_instalment')->where('loan_id', $single->loan_id)->sole())
        ->toMatchObject(['interest' => '300.00', 'amount' => '10300.00', 'due_date' => now('UTC')->addMonthsNoOverflow(3)->toDateString()]);
});

it('answers 409 to a second approval and pays out once', function () {
    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")->assertOk();

    $this->actingAs(User::factory()->admin()->create());
    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This loan is no longer awaiting approval.');

    expect(balanceOfAccount($this->account))->toBe('11500.00')
        ->and(DB::table('transactions')->count())->toBe(1);
});

it('answers 409 when the customer cancelled before the admin approved', function () {
    $this->actingAs($this->customer);
    $this->postJson("/api/v1/customer/loans/{$this->loan->loan_id}/cancel")->assertOk();

    $this->actingAs($this->admin);
    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This loan is no longer awaiting approval.');

    expect(loanRow($this->loan)->status)->toBe('CANCELLED')
        ->and(balanceOfAccount($this->account))->toBe('1500.00')
        ->and(DB::table('loan_instalment')->count())->toBe(0);
});

it('answers 409 to approving a loan staff have not approved yet', function () {
    $borrower = bankCustomer();
    $pending = loanFor($borrower, accountFor($borrower, '0.00'));

    $this->postJson("/api/v1/admin/loans/{$pending->loan_id}/approve")->assertStatus(409);

    expect(loanRow($pending)->status)->toBe('PENDING');
});

it('refuses an admin approving a loan they approved as staff', function () {
    DB::table('loan')->where('loan_id', $this->loan->loan_id)->update(['staff_approved_by' => $this->admin->employee_id]);

    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('message', "The same person can't make both approvals.");

    expect(loanRow($this->loan)->status)->toBe('AWAITING_ADMIN');
});

it('refuses with the procedure\'s reason when the account was frozen meanwhile', function () {
    DB::table('account')->where('account_id', $this->account->account_id)->update(['status' => 'FROZEN']);

    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The disbursement account is frozen.');
});

it('rejects a loan awaiting approval, with a reason, and only that', function () {
    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/reject", ['reason' => 'Exposure too high'])->assertOk();

    expect(loanRow($this->loan))->toMatchObject(['status' => 'REJECTED', 'rejected_by' => $this->admin->employee_id, 'rejection_reason' => 'Exposure too high']);
    expect(json_decode(DB::table('audit_log')->where('action_type', 'LOAN_REJECTED')->sole()->details, true))->toMatchArray(['by' => 'admin']);

    $this->postJson("/api/v1/admin/loans/{$this->loan->loan_id}/reject", ['reason' => 'Again'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a loan awaiting admin approval can be rejected here.');

    $borrower = bankCustomer();
    $pending = loanFor($borrower, accountFor($borrower, '0.00'));
    $this->postJson("/api/v1/admin/loans/{$pending->loan_id}/reject", ['reason' => 'No'])->assertStatus(409);
});

it('manages loan types with audited changes and refuses a zero rate', function () {
    $id = $this->postJson('/api/v1/admin/loan-types', ['type_name' => 'business loan', 'interest_rate' => '18.50'])
        ->assertCreated()
        ->assertJsonPath('data.type_name', 'Business Loan')
        ->json('data.loan_type_id');

    $this->postJson('/api/v1/admin/loan-types', ['type_name' => 'Free', 'interest_rate' => '0'])
        ->assertUnprocessable()->assertJsonValidationErrors(['interest_rate' => 'The interest rate must be greater than zero.']);
    $this->postJson('/api/v1/admin/loan-types', ['type_name' => 'Huge', 'interest_rate' => '100'])
        ->assertUnprocessable()->assertJsonValidationErrors('interest_rate');
    $this->postJson('/api/v1/admin/loan-types', ['type_name' => 'Business Loan', 'interest_rate' => '10'])
        ->assertUnprocessable()->assertJsonValidationErrors('type_name');

    $this->putJson("/api/v1/admin/loan-types/{$id}", ['type_name' => 'Business Loan', 'interest_rate' => '20'])->assertOk();
    $this->getJson('/api/v1/admin/loan-types')->assertOk()->assertJsonFragment(['type_name' => 'Personal', 'loans_count' => 1]);
    $this->deleteJson("/api/v1/admin/loan-types/{$id}")->assertOk();

    expect(DB::table('audit_log')->whereIn('action_type', ['LOAN_TYPE_CREATED', 'LOAN_TYPE_UPDATED', 'LOAN_TYPE_DELETED'])->count())->toBe(3);
});

it('refuses to delete a loan type that loans use', function () {
    $this->deleteJson('/api/v1/admin/loan-types/'.loanType()->loan_type_id)
        ->assertStatus(409)
        ->assertJsonPath('message', "This loan type can't be deleted: it is used by 1 loan.");
});
