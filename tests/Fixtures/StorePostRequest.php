<?php

namespace AgentTools\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'status' => ['required', Rule::in(['draft', 'published'])],
            'views' => 'integer|min:0|max:1000',
            'tags' => 'array',
            'tags.*' => 'integer',
            'published_on' => 'nullable|date_format:Y-m-d',
            'author.name' => 'string',
        ];
    }
}
