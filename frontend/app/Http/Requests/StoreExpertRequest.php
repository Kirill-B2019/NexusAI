<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $keywords = $this->input('keywords');
        if (is_string($keywords)) {
            $keywords = array_values(array_filter(
                array_map('trim', explode(',', $keywords)),
                static fn (string $keyword): bool => $keyword !== '',
            ));
        } elseif ($keywords === null && $this->exists('keywords')) {
            $keywords = [];
        }

        $normalized = [];
        if ($this->exists('keywords')) {
            $normalized['keywords'] = $keywords;
        }
        if ($this->exists('is_enabled')) {
            $normalized['is_enabled'] = $this->boolean('is_enabled');
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'key' => $creating
                ? ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/']
                : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'system_prompt' => [$creating ? 'required' : 'sometimes', 'string', 'min:20'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:10'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'keywords' => ['sometimes', 'array', 'max:100'],
            'keywords.*' => ['string', 'max:100'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
