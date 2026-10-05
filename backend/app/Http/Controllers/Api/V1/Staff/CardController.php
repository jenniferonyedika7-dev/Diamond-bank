<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Requests\ReasonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BankCard;
use App\Queries\CardDirectory;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Card requests and blocked cards for accounts held at the staff member's
 * current branch (account.branch_id). A card on another branch's account is
 * answered with the same 404 as a missing one. An account has at most one
 * ACTIVE card: issue and unblock both refuse a second.
 */
class CardController extends StaffAreaController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:REQUESTED,ACTIVE,BLOCKED,REJECTED,EXPIRED'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($filters['search'] ?? '');

        $cards = CardDirectory::query()
            ->where('account.branch_id', $this->branch($request)->branch_id)
            ->where('bank_card.status', $filters['status'] ?? 'REQUESTED')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where(DB::raw("CONCAT(customer.first_name, ' ', customer.last_name)"), 'like', "%{$search}%")
                ->orWhere('account.account_number', 'like', "%{$search}%")
                ->orWhere('bank_card.last4', $search)))
            ->paginate($filters['per_page'] ?? 20)
            ->through(fn ($row) => CardDirectory::present($row, withCustomer: true));

        return ApiResponse::paginated('Cards.', $cards);
    }

    /** REQUESTED -> ACTIVE: generates the number and sets issue and expiry dates. */
    public function issue(Request $request, string $cardId): JsonResponse
    {
        return DB::transaction(function () use ($request, $cardId) {
            $card = $this->lockBranchCard($request, $cardId);
            if ($card === null) {
                return $this->notFound();
            }
            if ($card->status !== 'REQUESTED') {
                return ApiResponse::error('Only a requested card can be issued.', 409);
            }
            if ($card->account_status !== 'ACTIVE') {
                return ApiResponse::error("The account is not active, so the card can't be issued.", 422);
            }
            $loginStatus = DB::table('users')->where('customer_id', $card->customer_id)->value('status');
            if ($loginStatus !== 'ACTIVE') {
                return ApiResponse::error("The customer's login is not active, so the card can't be issued.", 422);
            }
            if ($this->hasOtherActiveCard($card)) {
                return ApiResponse::error('This account already has an active card.', 409);
            }

            $number = CardNumber::generateUnique();
            $issued = now()->startOfDay();
            $expiry = $issued->copy()->addYearsNoOverflow(config('bank.card_validity_years'))->endOfMonth();

            BankCard::findOrFail($card->bank_card_id)->forceFill([
                'card_number' => $number,
                'card_number_hash' => CardNumber::hash($number),
                'last4' => substr($number, -4),
                'status' => 'ACTIVE',
                'issued_date' => $issued->toDateString(),
                'expiry_date' => $expiry->toDateString(),
                'decided_by' => $request->user()->employee_id,
                'decided_at' => now(),
            ])->save();

            $this->audit->log('CARD_ISSUED', 'bank_card', $card->bank_card_id, [
                'account_number' => $card->account_number,
                'last4' => substr($number, -4),
                'before' => ['status' => 'REQUESTED'],
                'after' => ['status' => 'ACTIVE', 'expiry_date' => $expiry->toDateString()],
            ]);

            return ApiResponse::success('Card issued: '.BankCard::mask(substr($number, -4)).'.');
        });
    }

    /** REQUESTED -> REJECTED. The reason is shown to the customer. */
    public function reject(ReasonRequest $request, string $cardId): JsonResponse
    {
        $reason = $request->validated('reason');

        return DB::transaction(function () use ($request, $cardId, $reason) {
            $card = $this->lockBranchCard($request, $cardId);
            if ($card === null) {
                return $this->notFound();
            }
            if ($card->status !== 'REQUESTED') {
                return ApiResponse::error('Only a requested card can be rejected.', 409);
            }

            DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->update([
                'status' => 'REJECTED',
                'rejection_reason' => $reason,
                'decided_by' => $request->user()->employee_id,
                'decided_at' => now(),
            ]);

            $this->audit->log('CARD_REJECTED', 'bank_card', $card->bank_card_id, [
                'account_number' => $card->account_number,
                'before' => ['status' => 'REQUESTED'],
                'after' => ['status' => 'REJECTED'],
                'reason' => $reason,
            ]);

            return ApiResponse::success('Card request rejected.');
        });
    }

    /** BLOCKED -> ACTIVE, unless a replacement is already active on the account. */
    public function unblock(Request $request, string $cardId): JsonResponse
    {
        return DB::transaction(function () use ($request, $cardId) {
            $card = $this->lockBranchCard($request, $cardId);
            if ($card === null) {
                return $this->notFound();
            }
            if ($card->status !== 'BLOCKED') {
                return ApiResponse::error('Only a blocked card can be unblocked.', 409);
            }
            if ($card->account_status !== 'ACTIVE') {
                return ApiResponse::error("The account is not active, so the card can't be unblocked.", 422);
            }
            if ($card->expiry_date !== null && $card->expiry_date < now()->toDateString()) {
                return ApiResponse::error("This card has expired and can't be unblocked.", 409);
            }
            if ($this->hasOtherActiveCard($card)) {
                return ApiResponse::error('A replacement card is already active on this account.', 409);
            }

            DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->update(['status' => 'ACTIVE']);

            $this->audit->log('CARD_UNBLOCKED', 'bank_card', $card->bank_card_id, [
                'account_number' => $card->account_number,
                'last4' => $card->last4,
                'before' => ['status' => 'BLOCKED'],
                'after' => ['status' => 'ACTIVE'],
            ]);

            return ApiResponse::success('Card unblocked.');
        });
    }

    /** The card and its account row, locked, if the account is held at this staff member's branch. */
    private function lockBranchCard(Request $request, string $cardId): ?object
    {
        return DB::table('bank_card')
            ->join('account', 'account.account_id', '=', 'bank_card.account_id')
            ->where('bank_card.bank_card_id', $cardId)
            ->where('account.branch_id', $this->branch($request)->branch_id)
            ->lockForUpdate()
            ->first([
                'bank_card.bank_card_id', 'bank_card.account_id', 'bank_card.status', 'bank_card.last4', 'bank_card.expiry_date',
                'account.account_number', 'account.status as account_status', 'account.customer_id',
            ]);
    }

    private function hasOtherActiveCard(object $card): bool
    {
        return DB::table('bank_card')
            ->where('account_id', $card->account_id)
            ->where('bank_card_id', '!=', $card->bank_card_id)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Card not found.', 404);
    }
}
