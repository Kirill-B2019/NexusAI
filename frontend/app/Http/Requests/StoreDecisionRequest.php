<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:300'],
            'content' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }
}
