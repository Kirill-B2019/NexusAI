<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StreamChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-projects') ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:1', 'max:32000'],
        ];
    }
}
