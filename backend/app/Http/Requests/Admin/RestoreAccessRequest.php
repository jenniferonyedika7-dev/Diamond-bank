<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** An administrator restores a staff member's login after checking who is asking. */
class RestoreAccessRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_channel' => ['required', 'in:IN_PERSON,PHONE_CALL'],
            'verification_note' => ['required', 'string', 'max:1000'],
            'request_type' => ['required', 'in:UNBLOCK_ONLY,UNBLOCK_AND_RESET'],
        ];
    }
}
