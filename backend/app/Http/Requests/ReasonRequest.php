<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Block, reject, freeze and similar actions: the reason is stored in the audit_log details. */
class ReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
