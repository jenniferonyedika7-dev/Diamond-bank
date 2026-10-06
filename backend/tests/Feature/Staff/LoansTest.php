<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->staff = staffAtBranch($this->branch);
    $this->customer = bankCustomer(['branch_id' => $this->branch->branch_id, 'first_name' => 'Awa', 'last_name' => 'Jallow']);
    $this->account = accountFor($this->customer, '1500.00');
    $this->loan = loanFor($this->customer, $this->account);

    // A loan at another branch.
    $otherBranch = Branch::factory()->create();
    $this->outsider = bankCustomer(['branch_id' => $otherBranch->branch_id]);
    $this->outsideLoan = loanFor($this->outsider, accountFor($this->outsider, '100.00'));

    $this->actingAs($this->staff);
});

function recordCheck(object $loan, string $check = 'BANK_STATEMENT', ?string $note = 'Six months of salary credits seen')
{
    return test()->putJson("/api/v1/staff/loans/{$loan->loan_id}/verifications/{$check}", ['note' => $note]);
}

it('lists loans at my branch only, by status, with the TIN masked and affordability shown', function () {
    $this->getJson('/api/v1/staff/loans')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.loan_id', $this->loan->loan_id)
        ->assertJsonPath('data.items.0.customer.name', 'Awa Jallow')
        ->assertJsonPath('data.items.0.tin', '******789')
        ->assertJsonPath('data.items.0.affordability.monthly_repayment', '888.49')
        ->assertJsonPath('data.items.0.affordability.percent_of_income', '3.6')
        ->assertJsonPath('data.items.0.affordability.above_warning', false)
        ->assertJsonPath('data.counts.PENDING', 1)
        ->assertJsonPath('data.counts.ACTIVE', 0);

    $this->getJson('/api/v1/staff/loans?status=AWAITING_ADMIN')->assertOk()->assertJsonPath('data.pagination.total', 0);
    $this->getJson('/api/v1/staff/loans?search=Jallow')->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/v1/staff/loans?search=Nobody')->assertJsonPath('data.pagination.total', 0);
    $this->getJson('/api/v1/staff/loans?status=APPROVED')->assertUnprocessable();
});

it('flags a repayment above 33% of monthly income', function () {
    DB::table('loan_application')->where('loan_id', $this->loan->loan_id)->update(['monthly_income' => '2000.00']);

    $this->getJson('/api/v1/staff/loans')
        ->assertJsonPath('data.items.0.affordability.percent_of_income', '44.4')
        ->assertJsonPath('data.items.0.affordability.above_warning', true)
        ->assertJsonPath('data.items.0.affordability.warning_percent', '33');
});

it('uses total repayable over the term for a single-payment loan\'s affordability', function () {
    $borrower = bankCustomer(['branch_id' => $this->branch->branch_id]);
    $single = loanFor($borrower, accountFor($borrower, '10.00'), ['repayment_plan' => 'SINGLE', 'loan_term_months' => 6]);
    DB::table('loan_application')->where('loan_id', $single->loan_id)->update(['monthly_income' => '5000.00']);

    $this->getJson("/api/v1/staff/loans/{$single->loan_id}")
        ->assertJsonPath('data.monthly_instalment', null)
        ->assertJsonPath('data.affordability.monthly_repayment', '1766.67') // 10,600 / 6
        ->assertJsonPath('data.affordability.percent_of_income', '35.3')
        ->assertJsonPath('data.affordability.above_warning', true);
});

it('shows the full application, guarantor, checks and approvals on the detail', function () {
    recordChecks($this->loan, $this->staff, ['BANK_STATEMENT']);

    $this->getJson("/api/v1/staff/loans/{$this->loan->loan_id}")
        ->assertOk()
        ->assertJsonPath('data.tin', '123456789')
        ->assertJsonPath('data.application.employment_type', 'OTHER')
        ->assertJsonPath('data.application.contact_phone', '7000000')
        ->assertJsonPath('data.guarantor.full_name', 'Fatou Sowe')
        ->assertJsonPath('data.verifications.0.check_type', 'BANK_STATEMENT')
        ->assertJsonPath('data.verifications.0.recorded', true)
        ->assertJsonPath('data.verifications.0.verified_by.employee_id', $this->staff->employee_id)
        ->assertJsonPath('data.verifications.1.recorded', false)
        ->assertJsonPath('data.staff_approval', null)
        ->assertJsonPath('data.schedule', []);
});

it('shows the customer\'s transactions for the last 6 months across all their accounts', function () {
    $second = accountFor($this->customer, '200.00');
    $typeId = DB::table('transaction_type')->where('type_name', 'DEPOSIT')->value('transaction_type_id');
    $insert = fn (object $account, string $when) => DB::table('transactions')->insert([
        'account_id' => $account->account_id, 'transaction_type_id' => $typeId, 'amount' => '10.00',
        'channel' => 'BRANCH', 'balance_after' => '10.00', 'transaction_date' => $when,
    ]);
    $insert($this->account, now()->subMonths(2));
    $insert($second, now()->subMonths(5)->subDays(25));
    $insert($this->account, now()->subMonths(6)->subDay()); // too old
    $insert(accountFor($this->outsider, '5.00'), now()->subDay()); // someone else

    $this->getJson("/api/v1/staff/loans/{$this->loan->loan_id}/statement")
        ->assertOk()
        ->assertJsonPath('data.months', 6)
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.items.0.account_number', $this->account->account_number)
        ->assertJsonPath('data.items.1.account_number', $second->account_number);
});

it('answers 404 for another branch\'s loan on every action', function () {
    $id = $this->outsideLoan->loan_id;

    $this->getJson("/api/v1/staff/loans/{$id}")->assertNotFound()->assertJsonPath('message', 'Loan not found.');
    $this->getJson("/api/v1/staff/loans/{$id}/statement")->assertNotFound();
    recordCheck($this->outsideLoan)->assertNotFound();
    $this->postJson("/api/v1/staff/loans/{$id}/approve")->assertNotFound();
    $this->postJson("/api/v1/staff/loans/{$id}/reject", ['reason' => 'No'])->assertNotFound();
    $this->getJson('/api/v1/staff/loans/999999')->assertNotFound();

    expect(loanRow($this->outsideLoan)->status)->toBe('PENDING')
        ->and(DB::table('loan_verification')->count())->toBe(0);
});

it('records a check with who and when, and keeps corrections in the audit log', function () {
    $this->travelTo(now()->startOfMinute());
    recordCheck($this->loan)->assertOk()->assertJsonPath('message', 'Bank statement reviewed: recorded.');

    $row = DB::table('loan_verification')->sole();
    expect($row)->toMatchObject([
        'loan_id' => $this->loan->loan_id, 'check_type' => 'BANK_STATEMENT',
        'note' => 'Six months of salary credits seen', 'verified_by' => $this->staff->employee_id,
    ]);

    $colleague = staffAtBranch($this->branch);
    $this->actingAs($colleague);
    recordCheck($this->loan, 'BANK_STATEMENT', 'Corrected: five months of credits')->assertOk();

    expect(DB::table('loan_verification')->sole())->toMatchObject(['note' => 'Corrected: five months of credits', 'verified_by' => $colleague->employee_id]);

    $audits = DB::table('audit_log')->where('action_type', 'LOAN_CHECK_RECORDED')->orderBy('audit_log_id')->get();
    expect($audits)->toHaveCount(2)
        ->and(json_decode($audits[0]->details, true)['before'])->toBeNull()
        ->and(json_decode($audits[1]->details, true))->toMatchArray([
            'check_type' => 'BANK_STATEMENT',
            'before' => ['note' => 'Six months of salary credits seen', 'verified_by' => $this->staff->employee_id, 'verified_at' => $row->verified_at],
        ]);
});

it('requires a note and a known check', function () {
    recordCheck($this->loan, 'BANK_STATEMENT', '')->assertUnprocessable()->assertJsonValidationErrors('note');
    recordCheck($this->loan, 'BANK_STATEMENT', str_repeat('x', 501))->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->putJson("/api/v1/staff/loans/{$this->loan->loan_id}/verifications/CREDIT_SCORE", ['note' => 'x'])->assertNotFound();
});

it('refuses to record checks once the loan has left PENDING', function () {
    DB::table('loan')->where('loan_id', $this->loan->loan_id)->update(['status' => 'CANCELLED']);

    recordCheck($this->loan)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Checks can only be recorded while the application is pending.');
});

it('refuses to approve until all four checks are recorded, naming the missing ones', function () {
    recordChecks($this->loan, $this->staff, ['BANK_STATEMENT', 'EMPLOYMENT']);

    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Record all four checks before approving. Missing: Guarantor contacted and aware of the loan, Guarantor occupation verified.')
        ->assertJsonPath('data.missing_checks', ['GUARANTOR_CONTACTED', 'GUARANTOR_OCCUPATION']);

    expect(loanRow($this->loan)->status)->toBe('PENDING');
});

it('approves: AWAITING_ADMIN with the approver and time recorded, and no money moved', function () {
    recordChecks($this->loan, $this->staff);

    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/approve")
        ->assertOk()
        ->assertJsonPath('message', "Loan approved. It now needs an administrator's approval.");

    expect(loanRow($this->loan))->toMatchObject(['status' => 'AWAITING_ADMIN', 'staff_approved_by' => $this->staff->employee_id, 'approved_by' => null])
        ->and(loanRow($this->loan)->staff_approved_at)->not->toBeNull()
        ->and(balanceOfAccount($this->account))->toBe('1500.00')
        ->and(DB::table('transactions')->count())->toBe(0)
        ->and(DB::table('loan_instalment')->count())->toBe(0);

    $audit = DB::table('audit_log')->where('action_type', 'LOAN_APPROVED_STAFF')->sole();
    expect($audit->user_id)->toBe($this->staff->user_id)
        ->and(json_decode($audit->details, true))->toMatchArray(['before' => ['status' => 'PENDING'], 'after' => ['status' => 'AWAITING_ADMIN']]);

    $this->getJson("/api/v1/staff/loans/{$this->loan->loan_id}")
        ->assertJsonPath('data.staff_approval.employee_id', $this->staff->employee_id)
        ->assertJsonPath('data.admin_approval', null);
});

it('answers 409 to a second staff approval', function () {
    recordChecks($this->loan, $this->staff);
    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/approve")->assertOk();

    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a pending loan application can be approved by staff.');

    expect(DB::table('audit_log')->where('action_type', 'LOAN_APPROVED_STAFF')->count())->toBe(1);
});

it('keeps admins out of the staff approval, so one person can\'t make both', function () {
    $admin = User::factory()->admin()->create();
    DB::table('employee_branch_lnk')->insert([
        'employee_id' => $admin->employee_id, 'branch_id' => $this->branch->branch_id,
        'start_date' => now()->toDateString(), 'end_date' => null,
    ]);
    recordChecks($this->loan, $this->staff);
    $this->actingAs($admin);

    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/approve")->assertForbidden();

    expect(loanRow($this->loan)->status)->toBe('PENDING');
});

it('rejects a pending loan with a reason the customer sees', function () {
    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/reject", [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->postJson("/api/v1/staff/loans/{$this->loan->loan_id}/reject", ['reason' => 'Guarantor could not be reached'])->assertOk();

    expect(loanRow($this->loan))->toMatchObject([
        'status' => 'REJECTED', 'rejected_by' => $this->staff->employee_id, 'rejection_reason' => 'Guarantor could not be reached',
    ]);
    $audit = DB::table('audit_log')->where('action_type', 'LOAN_REJECTED')->sole();
    expect(json_decode($audit->details, true))->toMatchArray(['by' => 'staff', 'reason' => 'Guarantor could not be reached', 'after' => ['status' => 'REJECTED']]);

    $this->actingAs($this->customer);
    $this->getJson("/api/v1/customer/loans/{$this->loan->loan_id}")->assertJsonPath('data.rejection_reason', 'Guarantor could not be reached');
});

it('leaves loans awaiting an admin to the admin', function () {
    $borrower = bankCustomer(['branch_id' => $this->branch->branch_id]);
    $loan = awaitingAdminLoan($borrower, accountFor($borrower, '10.00'), $this->staff);

    $this->postJson("/api/v1/staff/loans/{$loan->loan_id}/reject", ['reason' => 'Changed my mind'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a pending loan application can be rejected here.');

    expect(loanRow($loan)->status)->toBe('AWAITING_ADMIN');
});
