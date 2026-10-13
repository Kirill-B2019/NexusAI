<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'nexus_project_id' => ['required', 'uuid', Rule::unique('projects', 'nexus_project_id')],
            'api_key' => ['required', 'string', 'min:1', 'max:4096'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $response = redirect()
            ->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($this->except('api_key'));

        throw new ValidationException($validator, $response);
    }
}
