<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/** Staff record how the customer got in touch and the identity checks they made. */
class AccessRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_channel' => ['required', 'in:BRANCH_VISIT,PHONE_CALL'],
            'dob_confirmed' => ['required', 'accepted'],
            'phone_confirmed' => ['required', 'accepted'],
            'history_reviewed' => ['required', 'accepted'],
            'declaration_confirmed' => ['required', 'accepted'],
            'request_type' => ['required', 'in:UNBLOCK_ONLY,UNBLOCK_AND_RESET'],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'dob_confirmed.accepted' => "Confirm the customer's date of birth.",
            'phone_confirmed.accepted' => "Confirm the customer's registered phone number.",
            'history_reviewed.accepted' => "Review the customer's account and transaction history.",
            'declaration_confirmed.accepted' => 'Confirm the declaration.',
            'dob_confirmed.required' => "Confirm the customer's date of birth.",
            'phone_confirmed.required' => "Confirm the customer's registered phone number.",
            'history_reviewed.required' => "Review the customer's account and transaction history.",
            'declaration_confirmed.required' => 'Confirm the declaration.',
        ];
    }
}
