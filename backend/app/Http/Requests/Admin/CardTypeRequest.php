<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CardTypeRequest extends FormRequest
{
    /** Names are stored in Title Case ("SAVINGS" -> "Savings"). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type_name'))) {
            $this->merge(['type_name' => Str::title(trim($this->input('type_name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'type_name' => [
                'required', 'string', 'max:50', 'not_regex:/credit/i',
                Rule::unique('card_type', 'type_name')->ignore($this->route('card_type')?->card_type_id, 'card_type_id'),
            ],
            'daily_limit' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'type_name.not_regex' => 'Only debit cards are supported.',
        ];
    }
}
