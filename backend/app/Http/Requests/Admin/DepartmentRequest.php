<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    /** Names are stored in Title Case ("SAVINGS" -> "Savings"). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('department_name'))) {
            $this->merge(['department_name' => Str::title(trim($this->input('department_name')))]);
        }
    }

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
