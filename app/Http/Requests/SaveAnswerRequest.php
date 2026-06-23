<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['question_option_id' => ['nullable', 'integer', 'exists:question_options,id'], 'answer_text' => ['nullable', 'string'], 'is_flagged' => ['sometimes', 'boolean']];
    }
}
