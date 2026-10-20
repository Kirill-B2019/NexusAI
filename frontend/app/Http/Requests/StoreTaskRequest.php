<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignee_expert' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
