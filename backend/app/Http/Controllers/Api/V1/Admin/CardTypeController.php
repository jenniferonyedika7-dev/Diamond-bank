<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\CardTypeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CardType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CardTypeController extends AdminController
{
    public function index(): JsonResponse
    {
        $types = CardType::query()
            ->select('card_type.*')
            ->selectSub(DB::table('bank_card')->selectRaw('count(*)')->whereColumn('bank_card.card_type_id', 'card_type.card_type_id'), 'cards_count')
            ->orderBy('type_name')
            ->get();

        return ApiResponse::success('Card types.', $types);
    }

    public function store(CardTypeRequest $request): JsonResponse
    {
        $type = DB::transaction(function () use ($request) {
            $type = CardType::create($request->validated());
            $this->audit->log('CARD_TYPE_CREATED', 'card_type', $type->card_type_id, ['before' => null, 'after' => $this->snapshot($type)]);

            return $type;
        });

        return ApiResponse::success('Card type created.', $type->refresh(), 201);
    }

    public function update(CardTypeRequest $request, CardType $cardType): JsonResponse
    {
        DB::transaction(function () use ($request, $cardType) {
            $before = $this->snapshot($cardType);
            $cardType->update($request->validated());
            $this->audit->log('CARD_TYPE_UPDATED', 'card_type', $cardType->card_type_id, ['before' => $before, 'after' => $this->snapshot($cardType->refresh())]);
        });

        return ApiResponse::success('Card type updated.', $cardType);
    }

    public function destroy(CardType $cardType): JsonResponse
    {
        return $this->deleteUnlessUsed($cardType, 'card type', [
            'bank_card' => ['card_type_id', 'card', 'cards'],
        ], 'CARD_TYPE_DELETED', 'Card type deleted.');
    }
}
