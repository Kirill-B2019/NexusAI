<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
