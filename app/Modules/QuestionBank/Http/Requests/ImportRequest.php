<?php

namespace App\Modules\QuestionBank\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['legion', 'aiken', 'gift'])],
            'body' => ['required', 'string'],
            'defaults' => ['sometimes', 'array'],
        ];
    }
}
