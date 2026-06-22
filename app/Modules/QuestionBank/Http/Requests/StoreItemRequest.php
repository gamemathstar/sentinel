<?php

namespace App\Modules\QuestionBank\Http\Requests;

use App\Modules\QuestionBank\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy enforced in the controller
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(Item::TYPES)],
            'content' => ['required', 'array'],
            'content.stem' => ['required', 'string'],
            'content.options' => ['sometimes', 'array'],
            'answer' => ['nullable', 'array'],
            'metadata' => ['sometimes', 'array'],
            'metadata.bloom_level' => ['sometimes', 'integer', 'between:1,6'],
            'metadata.difficulty' => ['sometimes', 'numeric', 'between:0,1'],
            'metadata.expected_seconds' => ['sometimes', 'integer', 'min:1'],
            'org_node_ids' => ['sometimes', 'array'],
            'org_node_ids.*' => ['uuid'],
            'stimulus_id' => ['nullable', 'uuid'],
        ];
    }
}
