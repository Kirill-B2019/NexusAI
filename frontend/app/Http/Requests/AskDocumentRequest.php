<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:1', 'max:8000'],
            'expert' => ['nullable', 'string', 'max:100'],
            'thinking' => ['sometimes', 'boolean'],
            'rag_top_k' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'rag_min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
