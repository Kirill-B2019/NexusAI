<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StreamChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-projects') ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:1', 'max:32000'],
            'expert_mode' => ['nullable', 'string', 'in:auto,single,manual'],
            'expert' => ['nullable', 'string', 'max:100', 'required_if:expert_mode,single'],
            'experts' => ['nullable', 'array', 'min:1', 'max:4', 'required_if:expert_mode,manual'],
            'experts.*' => ['string', 'max:100', 'distinct'],
            'thinking' => ['nullable', 'boolean'],
            'use_llm_aggregator' => ['nullable', 'boolean'],
            'use_rag' => ['nullable', 'boolean'],
            'conversation_id' => ['nullable', 'string', 'max:100'],
            'save_to_conversation' => ['nullable', 'boolean'],
        ];
    }
}
