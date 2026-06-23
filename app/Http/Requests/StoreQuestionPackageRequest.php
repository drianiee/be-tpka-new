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
        return ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'status' => ['sometimes', 'in:draft,published,archived'], 'total_duration_minutes' => ['required', 'integer', 'min:1']];
    }
}
