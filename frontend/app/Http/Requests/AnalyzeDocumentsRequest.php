<?php

namespace App\Http\Requests;

class AnalyzeDocumentsRequest extends AskDocumentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'document_ids' => ['required', 'array', 'min:1', 'max:10'],
            'document_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
    }
}
