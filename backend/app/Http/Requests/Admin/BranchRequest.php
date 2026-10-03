<?php

namespace App\Http\Requests\Admin;

use App\Models\Bank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Create and update. bank_id is never taken from input: it is the single bank's id. */
class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_name' => ['required', 'string', 'max:100'],
            'branch_code' => [
                'required', 'string', 'regex:/^[A-Z0-9-]{1,20}$/',
                Rule::unique('branch', 'branch_code')->ignore($this->route('branch')?->branch_id, 'branch_id'),
            ],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'opened_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_code.regex' => 'The branch code may only contain letters, digits and hyphens (max 20).',
            'opened_date.before_or_equal' => 'The opened date cannot be in the future.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Checked first, so this is the only error returned when there is no bank.
        if ($this->isMethod('post') && ! Bank::query()->exists()) {
            throw ValidationException::withMessages(['bank' => 'Set up the bank details first.']);
        }

        if (is_string($this->input('branch_code'))) {
            $this->merge(['branch_code' => strtoupper(trim($this->input('branch_code')))]);
        }
    }
}
