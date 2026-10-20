<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExternalProjectKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'allowed_experts' => ['nullable', 'array', 'max:100'],
            'allowed_experts.*' => ['required', 'string', 'max:100', 'distinct'],
            'rate_limit_per_min' => ['required', 'integer', 'min:1', 'max:10000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => array_merge(
                ['nullable', 'date', 'after:now'],
                $this->filled('starts_at') ? ['after:starts_at'] : [],
            ),
        ];
    }
}
