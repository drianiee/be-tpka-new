<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['question_package_id' => ['required', 'exists:question_packages,id'], 'partner_id' => ['nullable', 'exists:partners,id'], 'description' => ['nullable', 'string'], 'start_at' => ['required', 'date'], 'end_at' => ['nullable', 'date', 'after:start_at'], 'registration_start_at' => ['nullable', 'date'], 'registration_end_at' => ['nullable', 'date', 'after_or_equal:registration_start_at'], 'price' => ['nullable', 'numeric', 'min:0'], 'quota' => ['nullable', 'integer', 'min:1'], 'is_partner' => ['nullable', 'boolean']];
    }
}
