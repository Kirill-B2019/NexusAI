<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingDatasetBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    public function rules(): array
    {
        return [
            'expert' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:51200', 'extensions:jsonl,csv'],
        ];
    }
}
