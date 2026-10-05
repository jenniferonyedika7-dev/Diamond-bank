<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Requests\Customer\CardRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BankCard;
use App\Models\CardType;
use App\Queries\CardDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Debit cards on the customer's own accounts. An account has at most one live
 * card (REQUESTED or ACTIVE, any type); a BLOCKED card doesn't count, so a lost
 * card can be blocked and replaced. Card numbers are only ever shown masked.
 */
class CardController extends CustomerAreaController
{
    public function index(Request $request): JsonResponse
    {
        $cards = CardDirectory::query()
            ->where('account.customer_id', $this->customerId($request))
            ->get()
            ->map(fn ($row) => CardDirectory::present($row));

        return ApiResponse::success('Cards.', $cards);
    }

    /** Read-only list for the request form. */
    public function types(): JsonResponse
    {
        return ApiResponse::success('Card types.', CardType::query()->orderBy('type_name')->get());
    }

    public function store(CardRequest $request): JsonResponse
    {
        $account = $this->ownAccount($request, $request->validated('account_number'));
        $type = CardType::findOrFail($request->validated('card_type_id'));

        if (stripos($type->type_name, 'credit') !== false) {
            return ApiResponse::error('Only debit cards are supported.', 422);
        }

        $kyc = DB::table('customer')->where('customer_id', $account->customer_id)->value('kyc_status');
        if ($kyc !== 'VERIFIED') {
            return ApiResponse::error('Your identity must be verified before you can request a card.', 422);
        }

        return DB::transaction(function () use ($request, $account, $type) {
            // Locking the account serializes card requests and staff card actions on it.
            $status = DB::table('account')->where('account_id', $account->account_id)->lockForUpdate()->value('status');
            if ($status !== 'ACTIVE') {
                return ApiResponse::error('Cards can only be requested for an active account.', 422);
            }

            $hasLiveCard = DB::table('bank_card')
                ->where('account_id', $account->account_id)
                ->whereIn('status', BankCard::LIVE_STATUSES)
                ->exists();
            if ($hasLiveCard) {
                return ApiResponse::error('This account already has an active card or a pending request.', 409);
            }

            $cardId = DB::table('bank_card')->insertGetId([
                'account_id' => $account->account_id,
                'card_type_id' => $type->card_type_id,
                'status' => 'REQUESTED',
                'requested_by' => $request->user()->user_id,
                'requested_at' => now(),
            ], 'bank_card_id');

            $this->audit->log('CARD_REQUESTED', 'bank_card', $cardId, [
                'account_number' => $account->account_number,
                'card_type' => $type->type_name,
                'before' => null,
                'after' => ['status' => 'REQUESTED'],
            ]);

            $row = CardDirectory::query()->where('bank_card.bank_card_id', $cardId)->first();

            return ApiResponse::success('Card requested. The bank will review your request.', CardDirectory::present($row), 201);
        });
    }

    /** ACTIVE -> BLOCKED, e.g. a lost card. Staff at the account's branch can unblock it. */
    public function block(Request $request, string $cardId): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $customerId = $this->customerId($request);

        return DB::transaction(function () use ($cardId, $customerId, $reason) {
            $card = $this->ownCard($customerId, $cardId, lock: true);

            if ($card === null) {
                return ApiResponse::error('Card not found.', 404);
            }
            if ($card->status !== 'ACTIVE') {
                return ApiResponse::error('Only an active card can be blocked.', 409);
            }

            DB::table('bank_card')->where('bank_card_id', $card->bank_card_id)->update(['status' => 'BLOCKED']);

            $this->audit->log('CARD_BLOCKED', 'bank_card', $card->bank_card_id, [
                'by' => 'customer',
                'account_number' => $card->account_number,
                'last4' => $card->last4,
                'before' => ['status' => 'ACTIVE'],
                'after' => ['status' => 'BLOCKED'],
                ...($reason !== null ? ['reason' => $reason] : []),
            ]);

            return ApiResponse::success('Card blocked. You can request a replacement.');
        });
    }

    /**
     * The full number of one of the customer's own ACTIVE cards, after their
     * password. The only endpoint that decrypts a card number. It shares the
     * transfer throttle (one budget of password attempts), audits last4 only,
     * and the response must not be cached.
     */
    public function reveal(Request $request, string $cardId): JsonResponse
    {
        $password = $request->validate(['password' => ['required', 'string']])['password'];

        $card = $this->ownCard($this->customerId($request), $cardId);
        if ($card === null) {
            return ApiResponse::error('Card not found.', 404);
        }
        if ($card->status !== 'ACTIVE') {
            return ApiResponse::error("Only an active card's number can be shown.", 409);
        }

        $this->confirmPassword($request, $password, 'CARD_REVEAL_PASSWORD_FAILED', [
            'bank_card_id' => $card->bank_card_id,
            'last4' => $card->last4,
        ]);

        $number = BankCard::findOrFail($card->bank_card_id)->card_number;

        $this->audit->log('CARD_NUMBER_REVEALED', 'bank_card', $card->bank_card_id, [
            'account_number' => $card->account_number,
            'last4' => $card->last4,
        ]);

        return ApiResponse::success('Card number.', [
            'bank_card_id' => $card->bank_card_id,
            'card_number' => $number,
            'last4' => $card->last4,
            'expiry_date' => $card->expiry_date,
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    /** The card if it is on one of this customer's accounts, otherwise null (callers answer 404). */
    private function ownCard(int $customerId, string $cardId, bool $lock = false): ?object
    {
        return DB::table('bank_card')
            ->join('account', 'account.account_id', '=', 'bank_card.account_id')
            ->where('bank_card.bank_card_id', $cardId)
            ->where('account.customer_id', $customerId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first(['bank_card.bank_card_id', 'bank_card.status', 'bank_card.last4', 'bank_card.expiry_date', 'account.account_number']);
    }
}
