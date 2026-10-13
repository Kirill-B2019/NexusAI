<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['nullable', 'string', 'json', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('metadata') || blank($this->input('metadata'))) {
                    return;
                }

                $metadata = json_decode($this->string('metadata')->toString());

                if (! $metadata instanceof stdClass) {
                    $validator->errors()->add('metadata', 'Метаданные должны быть JSON-объектом.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataObject(): array
    {
        $metadata = $this->input('metadata');

        return filled($metadata) ? (array) json_decode($metadata, false, 512, JSON_THROW_ON_ERROR) : [];
    }
}
