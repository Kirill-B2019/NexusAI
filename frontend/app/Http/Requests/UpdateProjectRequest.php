<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['required', 'string', 'json', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('metadata') || ! filled($this->input('metadata'))) {
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
        return (array) json_decode($this->string('metadata')->toString(), false, 512, JSON_THROW_ON_ERROR);
    }
}
