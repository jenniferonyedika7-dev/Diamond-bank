<?php

namespace App\Queries;

use App\Models\Loan;
use App\Support\Amortisation;
use App\Support\InstalmentStatus;
use App\Support\Tin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loan listings and detail for the customer, staff and admin areas.
 *
 * Customers see their loan, its schedule and a rejection reason, never the
 * affordability figure, verification notes or approver names. Staff and admins
 * also see the application, guarantor, checks and affordability; lists mask
 * the TIN, and only the detail shows it in full.
 */
class LoanDirectory
{
    public static function query(): Builder
    {
        return DB::table('loan')
            ->join('loan_type', 'loan_type.loan_type_id', '=', 'loan.loan_type_id')
            ->join('customer', 'customer.customer_id', '=', 'loan.customer_id')
            ->join('account', 'account.account_id', '=', 'loan.account_id')
            ->join('branch', 'branch.branch_id', '=', 'loan.branch_id')
            ->join('loan_application', 'loan_application.loan_id', '=', 'loan.loan_id')
            ->orderByDesc('loan.application_date')
            ->orderByDesc('loan.loan_id')
            ->select([
                'loan.loan_id', 'loan.status', 'loan.loan_amount', 'loan.interest_rate', 'loan.loan_term_months',
                'loan.repayment_plan', 'loan.monthly_instalment', 'loan.total_interest', 'loan.total_repayable',
                'loan.application_date', 'loan.staff_approved_at', 'loan.approval_date', 'loan.rejected_at',
                'loan.rejection_reason', 'loan.cancelled_at', 'loan.closed_at', 'loan.customer_id', 'loan.branch_id',
                'loan.staff_approved_by', 'loan.approved_by', 'loan.rejected_by', 'loan.disbursement_transaction_id',
                'loan_type.loan_type_id', 'loan_type.type_name',
                'account.account_number', 'account.status as account_status',
                'branch.branch_name', 'branch.branch_code',
                'customer.first_name', 'customer.last_name',
                'loan_application.purpose_category', 'loan_application.monthly_income', 'loan_application.tin',
            ]);
    }

    /** Staff and admin list search: customer name, account number or loan id. */
    public static function search(Builder $query, string $search): Builder
    {
        return $query->where(fn ($q) => $q
            ->where(DB::raw("CONCAT(customer.first_name, ' ', customer.last_name)"), 'like', "%{$search}%")
            ->orWhere('account.account_number', 'like', "%{$search}%")
            ->when(ctype_digit($search), fn ($q) => $q->orWhere('loan.loan_id', (int) $search)));
    }

    /** @return array<string, mixed> */
    public static function presentForCustomer(object $row): array
    {
        return [
            'loan_id' => $row->loan_id,
            'status' => $row->status,
            'loan_type' => ['loan_type_id' => $row->loan_type_id, 'type_name' => $row->type_name],
            'amount' => $row->loan_amount,
            'interest_rate' => $row->interest_rate,
            'term_months' => $row->loan_term_months,
            'repayment_plan' => $row->repayment_plan,
            'monthly_instalment' => $row->monthly_instalment,
            'total_interest' => $row->total_interest,
            'total_repayable' => $row->total_repayable,
            'account_number' => $row->account_number,
            'purpose_category' => $row->purpose_category,
            'application_date' => $row->application_date,
            'approval_date' => $row->approval_date,
            'rejection_reason' => $row->status === 'REJECTED' ? $row->rejection_reason : null,
            'rejected_at' => $row->rejected_at,
            'cancelled_at' => $row->cancelled_at,
            'closed_at' => $row->closed_at,
            'can_cancel' => in_array($row->status, ['PENDING', 'AWAITING_ADMIN'], true),
        ];
    }

    /** List row for staff and admins: TIN masked. @return array<string, mixed> */
    public static function presentForStaff(object $row): array
    {
        return [
            ...self::presentForCustomer($row),
            'rejection_reason' => $row->rejection_reason,
            'customer' => ['customer_id' => $row->customer_id, 'name' => "{$row->first_name} {$row->last_name}"],
            'branch' => ['branch_id' => $row->branch_id, 'branch_name' => $row->branch_name, 'branch_code' => $row->branch_code],
            'account_status' => $row->account_status,
            'monthly_income' => $row->monthly_income,
            'tin' => Tin::mask($row->tin),
            'affordability' => self::affordability($row),
            'staff_approved_at' => $row->staff_approved_at,
        ];
    }

    /** The customer's own view of one loan, with its schedule. @return array<string, mixed> */
    public static function customerDetail(object $row): array
    {
        return [
            ...self::presentForCustomer($row),
            'schedule' => self::schedule($row->loan_id),
        ];
    }

    /** Everything staff and admins need to decide on a loan. @return array<string, mixed> */
    public static function staffDetail(object $row): array
    {
        $application = DB::table('loan_application')->where('loan_id', $row->loan_id)->first();
        $employees = DB::table('employee')
            ->whereIn('employee_id', array_filter([$row->staff_approved_by, $row->approved_by, $row->rejected_by]))
            ->pluck('full_name', 'employee_id');
        $person = fn (?int $employeeId, $at) => $employeeId === null ? null
            : ['employee_id' => $employeeId, 'name' => $employees[$employeeId] ?? null, 'at' => $at];

        return [
            ...self::presentForStaff($row),
            'tin' => $application->tin,
            'application' => collect((array) $application)->except(['loan_id'])->all(),
            'guarantor' => collect((array) DB::table('loan_guarantor')->where('loan_id', $row->loan_id)->first())->except(['loan_id'])->all(),
            'verifications' => self::verifications($row->loan_id),
            'staff_approval' => $person($row->staff_approved_by, $row->staff_approved_at),
            'admin_approval' => $person($row->approved_by, $row->approval_date),
            'rejection' => $person($row->rejected_by, $row->rejected_at),
            'disbursement_transaction_id' => $row->disbursement_transaction_id,
            'schedule' => self::schedule($row->loan_id),
        ];
    }

    /**
     * The four checks in a fixed order; a check not yet recorded has recorded = false.
     *
     * @return list<array<string, mixed>>
     */
    public static function verifications(int $loanId): array
    {
        $recorded = DB::table('loan_verification')
            ->join('employee', 'employee.employee_id', '=', 'loan_verification.verified_by')
            ->where('loan_verification.loan_id', $loanId)
            ->get(['loan_verification.check_type', 'loan_verification.note', 'loan_verification.verified_at',
                'employee.employee_id', 'employee.full_name'])
            ->keyBy('check_type');

        return collect(Loan::CHECKS)->map(function (string $check) use ($recorded) {
            $row = $recorded[$check] ?? null;

            return [
                'check_type' => $check,
                'label' => Loan::CHECK_LABELS[$check],
                'recorded' => $row !== null,
                'note' => $row?->note,
                'verified_by' => $row === null ? null : ['employee_id' => $row->employee_id, 'name' => $row->full_name],
                'verified_at' => $row?->verified_at,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    public static function schedule(int $loanId): array
    {
        $rows = DB::table('loan_instalment')
            ->where('loan_id', $loanId)
            ->orderBy('instalment_number')
            ->get(['instalment_number', 'due_date', 'principal', 'interest', 'amount', 'balance_after', 'status', 'paid_at']);

        return InstalmentStatus::for($rows, now());
    }

    /**
     * The customer's transactions on all their Diamond Bank accounts over the
     * last config('bank.loans.statement_months') months, newest first.
     */
    public static function statement(int $customerId, int $perPage): LengthAwarePaginator
    {
        return DB::table('transactions')
            ->join('account', 'account.account_id', '=', 'transactions.account_id')
            ->join('transaction_type', 'transaction_type.transaction_type_id', '=', 'transactions.transaction_type_id')
            ->where('account.customer_id', $customerId)
            ->where('transactions.transaction_date', '>=', now()->subMonthsNoOverflow(config('bank.loans.statement_months')))
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.transaction_id')
            ->select(['transactions.transaction_id', 'account.account_number', 'transaction_type.type_name', 'transactions.amount',
                'transactions.balance_after', 'transactions.channel', 'transactions.description', 'transactions.transaction_date'])
            ->paginate($perPage);
    }

    /** @return array<string, mixed> */
    private static function affordability(object $row): array
    {
        $repayment = Amortisation::monthlyRepayment([
            'repayment_plan' => $row->repayment_plan,
            'monthly_instalment' => $row->monthly_instalment,
            'total_repayable' => $row->total_repayable,
            'term_months' => $row->loan_term_months,
        ]);

        return Amortisation::affordability($repayment, $row->monthly_income, (string) config('bank.loans.affordability_warning_percent'));
    }
}
