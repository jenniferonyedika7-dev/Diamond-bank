<?php

namespace App\Http\Requests\Customer;

use App\Support\Tin;
use Illuminate\Validation\Validator;

/**
 * A loan application: the terms (LoanQuoteRequest), the disbursement account,
 * purpose, income, employment and one guarantor. Which employment fields are
 * required depends on employment_type; the others are discarded (application()).
 */
class LoanApplicationRequest extends LoanQuoteRequest
{
    public const PURPOSES = ['BUSINESS', 'EDUCATION', 'MEDICAL', 'HOME', 'PERSONAL', 'OTHER'];

    /** employment_type => the fields it requires. */
    public const EMPLOYMENT_FIELDS = [
        'EMPLOYED' => ['employer_name', 'workplace_address', 'employer_phone', 'job_title'],
        'BUSINESS_OWNER' => ['business_name', 'business_registration_number'],
        'CONTENT_CREATOR' => ['platform', 'account_handle'],
        'OTHER' => ['employment_description'],
    ];

    private const PHONE = 'regex:/^\+?[0-9 ]{7,20}$/';

    public function rules(): array
    {
        $requiredFor = fn (string $type) => ['nullable', 'required_if:employment_type,'.$type, 'string'];

        return [
            ...parent::rules(),
            'account_number' => ['required', 'string', 'max:20'],
            'purpose_category' => ['required', 'in:'.implode(',', self::PURPOSES)],
            'purpose_description' => ['required', 'string', 'max:500'],
            'monthly_income' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'tin' => ['required', 'string', 'regex:'.Tin::PATTERN],
            'employment_type' => ['required', 'in:'.implode(',', array_keys(self::EMPLOYMENT_FIELDS))],
            'employer_name' => [...$requiredFor('EMPLOYED'), 'max:150'],
            'workplace_address' => [...$requiredFor('EMPLOYED'), 'max:255'],
            'employer_phone' => [...$requiredFor('EMPLOYED'), self::PHONE],
            'job_title' => [...$requiredFor('EMPLOYED'), 'max:100'],
            'business_name' => [...$requiredFor('BUSINESS_OWNER'), 'max:150'],
            'business_registration_number' => [...$requiredFor('BUSINESS_OWNER'), 'max:50'],
            'platform' => [...$requiredFor('CONTENT_CREATOR'), 'max:50'],
            'account_handle' => [...$requiredFor('CONTENT_CREATOR'), 'max:100'],
            'employment_description' => [...$requiredFor('OTHER'), 'max:500'],
            'guarantor' => ['required', 'array'],
            'guarantor.full_name' => ['required', 'string', 'max:150'],
            'guarantor.phone' => ['required', 'string', self::PHONE],
            'guarantor.occupation' => ['required', 'string', 'max:100'],
            'guarantor.address' => ['required', 'string', 'max:255'],
            'guarantor.email' => ['required', 'email', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'monthly_income.gt' => 'Monthly income must be greater than zero.',
            'monthly_income.decimal' => 'Amounts can have at most 2 decimal places.',
            'tin.regex' => 'The TIN must be 8 to 15 digits.',
            'employer_phone.regex' => 'Enter a valid phone number (digits, spaces and an optional leading +).',
            'guarantor.phone.regex' => 'Enter a valid phone number (digits, spaces and an optional leading +).',
            'required_if' => 'The :attribute is required for this employment type.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tin' => 'TIN',
            'employer_name' => 'employer name',
            'workplace_address' => 'workplace address',
            'employer_phone' => 'employer phone',
            'job_title' => 'job title',
            'business_name' => 'business name',
            'business_registration_number' => 'business registration number',
            'platform' => 'platform',
            'account_handle' => 'account handle',
            'employment_description' => 'description of your work',
            'guarantor.full_name' => "guarantor's full name",
            'guarantor.phone' => "guarantor's phone",
            'guarantor.occupation' => "guarantor's occupation",
            'guarantor.address' => "guarantor's address",
            'guarantor.email' => "guarantor's email",
        ];
    }

    /** A guarantor can't share the applicant's profile phone or email. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $customer = $this->user()?->customer;
            if ($customer === null || $validator->errors()->has('guarantor.*')) {
                return;
            }

            $digits = fn (?string $phone) => preg_replace('/\D/', '', (string) $phone);
            if ($digits($this->input('guarantor.phone')) === $digits($customer->phone)) {
                $validator->errors()->add('guarantor.phone', "A guarantor can't be yourself: use someone else's phone number.");
            }
            if (strcasecmp(trim((string) $this->input('guarantor.email')), trim((string) $customer->email)) === 0) {
                $validator->errors()->add('guarantor.email', "A guarantor can't be yourself: use someone else's email.");
            }
        }];
    }

    /**
     * The loan_application columns, with the employment fields that don't
     * apply to employment_type set to null.
     *
     * @return array<string, mixed>
     */
    public function application(): array
    {
        $data = $this->validated();
        $applies = self::EMPLOYMENT_FIELDS[$data['employment_type']];
        $employment = [];
        foreach (array_merge(...array_values(self::EMPLOYMENT_FIELDS)) as $field) {
            $employment[$field] = in_array($field, $applies, true) ? ($data[$field] ?? null) : null;
        }

        return [
            'purpose_category' => $data['purpose_category'],
            'purpose_description' => $data['purpose_description'],
            'monthly_income' => (string) $this->input('monthly_income'),
            'tin' => $data['tin'],
            'employment_type' => $data['employment_type'],
            ...$employment,
        ];
    }
}
