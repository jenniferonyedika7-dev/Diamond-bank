<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveStaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branch,branch_id'],
            'department_id' => ['nullable', 'integer', 'exists:department,department_id'],
        ];
    }
}
