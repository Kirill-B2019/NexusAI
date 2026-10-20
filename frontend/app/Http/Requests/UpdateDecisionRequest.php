<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:300'],
            'content' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'string', 'in:active,superseded,archived'],
        ];
    }
}
