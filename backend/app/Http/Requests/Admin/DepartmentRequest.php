<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'department_name' => [
                'required', 'string', 'max:100',
                Rule::unique('department', 'department_name')->ignore($this->route('department')?->department_id, 'department_id'),
            ],
        ];
    }
}
