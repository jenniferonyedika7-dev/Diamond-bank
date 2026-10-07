<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->me = bankCustomer(['first_name' => 'Awa', 'last_name' => 'Jallow', 'phone' => '7700001', 'email' => 'awa@example.test']);
    $this->account = accountFor($this->me, '1500.00');
    loanType();
    $this->actingAs($this->me);
});

function applyForLoan(object $account, array $overrides = [])
{
    return test()->postJson('/api/v1/customer/loans', loanApplication($account, $overrides));
}

it('quotes both repayment plans from the backend without saving anything', function () {
    $type = loanType();

    $this->postJson('/api/v1/customer/loans/quote', ['loan_type_id' => $type->loan_type_id, 'amount' => '10000', 'term_months' => 12, 'repayment_plan' => 'MONTHLY'])
        ->assertOk()
        ->assertJsonPath('data.monthly_instalment', '888.49')
        ->assertJsonPath('data.total_interest', '661.86')
        ->assertJsonPath('data.total_repayable', '10661.86')
        ->assertJsonPath('data.interest_rate', '12.00')
        ->assertJsonCount(12, 'data.schedule')
        ->assertJsonMissingPath('data.affordability');

    $this->postJson('/api/v1/customer/loans/quote', ['loan_type_id' => $type->loan_type_id, 'amount' => '10000', 'term_months' => 6, 'repayment_plan' => 'SINGLE'])
        ->assertOk()
        ->assertJsonPath('data.monthly_instalment', null)
        ->assertJsonPath('data.total_interest', '600.00')
        ->assertJsonPath('data.total_repayable', '10600.00')
        ->assertJsonCount(1, 'data.schedule');

    expect(DB::table('loan')->count())->toBe(0);
});

it('shows the profile contact details, active accounts and limits on the apply form', function () {
    accountFor($this->me, '50.00', ['status' => 'FROZEN']);

    $this->getJson('/api/v1/customer/loans/apply-context')
        ->assertOk()
        ->assertJsonPath('data.contact.phone', '7700001')
        ->assertJsonPath('data.contact.email', 'awa@example.test')
        ->assertJsonPath('data.has_open_loan', false)
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.account_number', $this->account->account_number)
        ->assertJsonPath('data.limits.max_term_months', 12);
});

it('applies for a loan: loan, application snapshot, guarantor and audit', function () {
    applyForLoan($this->account, ['employer_phone' => '4227266', 'business_name' => 'Ignored Ltd'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.amount', '10000.00')
        ->assertJsonPath('data.monthly_instalment', '888.49')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonMissingPath('data.affordability')
        ->assertJsonMissingPath('data.tin');

    $loan = DB::table('loan')->sole();
    expect($loan)->toMatchObject([
        'customer_id' => $this->me->customer_id,
        'branch_id' => $this->account->branch_id,
        'account_id' => $this->account->account_id,
        'loan_type_id' => loanType()->loan_type_id,
        'loan_amount' => '10000.00',
        'interest_rate' => '12.00',
        'loan_term_months' => 12,
        'repayment_plan' => 'MONTHLY',
        'monthly_instalment' => '888.49',
        'total_interest' => '661.86',
        'total_repayable' => '10661.86',
        'status' => 'PENDING',
    ]);

    expect(DB::table('loan_application')->sole())->toMatchObject([
        'loan_id' => $loan->loan_id,
        'purpose_category' => 'BUSINESS',
        'monthly_income' => '25000.00',
        'tin' => '123456789',
        'employment_type' => 'EMPLOYED',
        'employer_name' => 'Gambia Ports Authority',
        'employer_phone' => '4227266',
        'business_name' => null, // not an EMPLOYED field, so not stored
        'contact_phone' => '7700001',
        'contact_email' => 'awa@example.test',
    ]);
    expect(DB::table('loan_guarantor')->sole())->toMatchObject(['loan_id' => $loan->loan_id, 'full_name' => 'Fatou Sowe', 'occupation' => 'Teacher']);

    $audit = DB::table('audit_log')->where('action_type', 'LOAN_APPLIED')->sole();
    expect($audit->user_id)->toBe($this->me->user_id)
        ->and($audit->details)->not->toContain('123456789')
        ->and(json_decode($audit->details, true))->toMatchArray(['tin' => '******789', 'after' => ['status' => 'PENDING'], 'amount' => '10000.00']);
});

it('keeps the rate the loan was quoted when the loan type changes later', function () {
    applyForLoan($this->account)->assertCreated();
    loanType()->update(['interest_rate' => '30.00']);

    $this->getJson('/api/v1/customer/loans')->assertOk()->assertJsonPath('data.0.interest_rate', '12.00');
});

it('refuses amounts and terms outside the limits with clear messages', function (array $overrides, string $field, string $message) {
    applyForLoan($this->account, $overrides)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(DB::table('loan')->count())->toBe(0);
})->with([
    'below the minimum' => [['amount' => '999.99'], 'amount', 'The minimum loan is D 1,000.00.'],
    'above the maximum' => [['amount' => '5000000.01'], 'amount', 'The maximum loan is D 5,000,000.00.'],
    'three decimals' => [['amount' => '1000.001'], 'amount', 'Amounts can have at most 2 decimal places.'],
    'zero months' => [['term_months' => 0], 'term_months', 'Loans can run for 1 to 12 months.'],
    'thirteen months' => [['term_months' => 13], 'term_months', 'Loans can run for 1 to 12 months.'],
    'unknown plan' => [['repayment_plan' => 'WEEKLY'], 'repayment_plan', 'Choose monthly instalments or a single payment at the end of the term.'],
    'zero income' => [['monthly_income' => '0'], 'monthly_income', 'Monthly income must be greater than zero.'],
    'short TIN' => [['tin' => '1234567'], 'tin', 'The TIN must be 8 to 15 digits.'],
    'TIN with letters' => [['tin' => 'TIN12345678'], 'tin', 'The TIN must be 8 to 15 digits.'],
]);

it('accepts the limits themselves', function () {
    applyForLoan($this->account, ['amount' => '1000', 'term_months' => 1])->assertCreated();
});

it('requires the employment fields for each employment type', function (string $type, array $required) {
    $body = loanApplication($this->account, ['employment_type' => $type]);
    foreach (['employer_name', 'workplace_address', 'employer_phone', 'job_title'] as $field) {
        unset($body[$field]);
    }

    $this->postJson('/api/v1/customer/loans', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($required);
})->with([
    'employed' => ['EMPLOYED', ['employer_name', 'workplace_address', 'employer_phone', 'job_title']],
    'business owner' => ['BUSINESS_OWNER', ['business_name', 'business_registration_number']],
    'content creator' => ['CONTENT_CREATOR', ['platform', 'account_handle']],
    'other' => ['OTHER', ['employment_description']],
]);

it('stores only the fields of the chosen employment type', function () {
    applyForLoan($this->account, [
        'employment_type' => 'CONTENT_CREATOR',
        'platform' => 'YouTube',
        'account_handle' => '@awacooks',
    ])->assertCreated();

    expect(DB::table('loan_application')->sole())->toMatchObject([
        'employment_type' => 'CONTENT_CREATOR',
        'platform' => 'YouTube',
        'account_handle' => '@awacooks',
        'employer_name' => null,
        'job_title' => null,
    ]);
});

it('requires a complete, valid guarantor who is not the applicant', function (array $guarantor, string $field) {
    applyForLoan($this->account, ['guarantor' => $guarantor])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no name' => [['full_name' => ''], 'guarantor.full_name'],
    'no occupation' => [['occupation' => ''], 'guarantor.occupation'],
    'no address' => [['address' => ''], 'guarantor.address'],
    'bad phone' => [['phone' => 'call me'], 'guarantor.phone'],
    'bad email' => [['email' => 'not-an-email'], 'guarantor.email'],
    'my own phone' => [['phone' => '770 0001'], 'guarantor.phone'],
    'my own email' => [['email' => 'AWA@example.test'], 'guarantor.email'],
]);

it('refuses an applicant whose identity is not verified', function () {
    DB::table('customer')->where('customer_id', $this->me->customer_id)->update(['kyc_status' => 'PENDING']);

    applyForLoan($this->account)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your identity must be verified before you can apply for a loan.');
});

it('refuses another customer\'s account and my frozen account', function () {
    $other = accountFor(bankCustomer(), '100.00');
    $frozen = accountFor($this->me, '100.00', ['status' => 'FROZEN']);

    foreach ([$other, $frozen] as $account) {
        applyForLoan($account)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_number' => 'Choose one of your own active accounts.']);
    }

    expect(DB::table('loan')->count())->toBe(0);
});

it('allows only one open loan', function (string $status) {
    loanFor($this->me, $this->account, ['status' => $status]);

    applyForLoan($this->account)
        ->assertStatus(409)
        ->assertJsonPath('message', 'You already have a loan application in progress or an active loan.');
})->with(['PENDING', 'AWAITING_ADMIN', 'ACTIVE']);

it('allows a new application after a rejected, cancelled or closed loan', function (string $status) {
    loanFor($this->me, $this->account, ['status' => $status]);

    applyForLoan($this->account)->assertCreated();
})->with(['REJECTED', 'CANCELLED', 'CLOSED']);

it('refuses a second application sent straight after the first', function () {
    applyForLoan($this->account)->assertCreated();
    applyForLoan($this->account)->assertStatus(409);

    expect(DB::table('loan')->count())->toBe(1);
});

it('lists and shows only my loans, with the schedule and the rejection reason', function () {
    $staff = staffAtBranch(Branch::find($this->account->branch_id));
    $mine = awaitingAdminLoan($this->me, $this->account, $staff);
    DB::select('CALL sp_disburse_loan(?, ?, ?)', [$mine->loan_id, json_encode(scheduleFor($mine)), User::factory()->admin()->create()->user_id]);
    loanFor($this->me, $this->account, ['status' => 'REJECTED', 'rejection_reason' => 'Income too low']);
    $other = bankCustomer();
    loanFor($other, accountFor($other, '100.00'));

    $this->getJson('/api/v1/customer/loans')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'REJECTED')
        ->assertJsonPath('data.0.rejection_reason', 'Income too low');

    $this->getJson("/api/v1/customer/loans/{$mine->loan_id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonCount(12, 'data.schedule')
        ->assertJsonPath('data.schedule.0.display_status', 'DUE')
        ->assertJsonPath('data.schedule.1.display_status', 'UPCOMING')
        ->assertJsonMissingPath('data.affordability')
        ->assertJsonMissingPath('data.verifications')
        ->assertJsonMissingPath('data.tin');

    $this->travel(40)->days();
    $this->getJson("/api/v1/customer/loans/{$mine->loan_id}")
        ->assertJsonPath('data.schedule.0.display_status', 'OVERDUE')
        ->assertJsonPath('data.schedule.1.display_status', 'DUE');
});

it('shows the customer the staff approval date, never the approver', function () {
    $staff = staffAtBranch(Branch::find($this->account->branch_id));
    $loan = awaitingAdminLoan($this->me, $this->account, $staff);

    $this->getJson("/api/v1/customer/loans/{$loan->loan_id}")
        ->assertOk()
        ->assertJsonPath('data.staff_approved_at', fn ($at) => $at !== null)
        ->assertJsonPath('data.approval_date', null)
        ->assertJsonMissingPath('data.staff_approval');
    $this->getJson('/api/v1/customer/loans')->assertJsonPath('data.0.staff_approved_at', fn ($at) => $at !== null);

    DB::table('loan')->where('loan_id', $loan->loan_id)->update(['status' => 'CANCELLED', 'staff_approved_by' => null, 'staff_approved_at' => null]);
    $this->getJson("/api/v1/customer/loans/{$loan->loan_id}")->assertJsonPath('data.staff_approved_at', null);
});

it('rate-limits quotes to 30 per minute, separately from transfer lookups', function () {
    $body = ['loan_type_id' => loanType()->loan_type_id, 'amount' => '10000', 'term_months' => 12, 'repayment_plan' => 'MONTHLY'];
    foreach (range(1, 30) as $i) {
        $this->postJson('/api/v1/customer/loans/quote', $body)->assertOk();
    }
    $this->postJson('/api/v1/customer/loans/quote', $body)
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $this->postJson('/api/v1/customer/transfers/lookup', ['to_account_number' => 'DB0019999999'])->assertUnprocessable();
});

it('answers 404 for another customer\'s loan, on show and cancel', function () {
    $other = bankCustomer();
    $theirs = loanFor($other, accountFor($other, '100.00'));

    $this->getJson("/api/v1/customer/loans/{$theirs->loan_id}")->assertNotFound()->assertJsonPath('message', 'Loan not found.');
    $this->getJson('/api/v1/customer/loans/999999')->assertNotFound()->assertJsonPath('message', 'Loan not found.');
    $this->postJson("/api/v1/customer/loans/{$theirs->loan_id}/cancel")->assertNotFound();

    expect(loanRow($theirs)->status)->toBe('PENDING');
});

it('cancels a loan that is still being reviewed', function (string $status) {
    $loan = loanFor($this->me, $this->account, ['status' => $status]);

    $this->postJson("/api/v1/customer/loans/{$loan->loan_id}/cancel")->assertOk();

    expect(loanRow($loan)->status)->toBe('CANCELLED')
        ->and(loanRow($loan)->cancelled_at)->not->toBeNull();
    $audit = DB::table('audit_log')->where('action_type', 'LOAN_CANCELLED')->sole();
    expect(json_decode($audit->details, true))->toEqual(['before' => ['status' => $status], 'after' => ['status' => 'CANCELLED']]);
})->with(['PENDING', 'AWAITING_ADMIN']);

it('refuses to cancel a decided loan with 409', function (string $status) {
    $loan = loanFor($this->me, $this->account, ['status' => $status]);

    $this->postJson("/api/v1/customer/loans/{$loan->loan_id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Only a loan application that is still being reviewed can be cancelled.');

    expect(loanRow($loan)->status)->toBe($status);
})->with(['ACTIVE', 'REJECTED', 'CANCELLED', 'CLOSED']);
