<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'total_duration_minutes' => ['required', 'integer', 'min:1'],
            'target_total_questions' => ['nullable', 'integer', 'min:0'],
            'sections' => ['nullable', 'array'],
            'sections.*.code' => ['required_with:sections', 'in:verbal,kuantitatif,logika,spasial'],
            'sections.*.duration_minutes' => ['required_with:sections', 'integer', 'min:1'],
            'sections.*.target_question_count' => ['required_with:sections', 'integer', 'min:0'],
        ];
    }
}
