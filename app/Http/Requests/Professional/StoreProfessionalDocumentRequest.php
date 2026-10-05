<?php

namespace App\Http\Requests\Professional;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfessionalDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentType::class)],

            /*
             * An identity document is uploaded once and reviewed by a human, so
             * the accepted set is deliberately narrow — a PDF or a photo of the
             * document, nothing else. The client-declared MIME type is ignored;
             * `mimes` inspects the file's actual content.
             */
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => 'Upload a JPG, PNG or PDF file.',
            'document.max' => 'The document must be 5 MB or smaller.',
            'document.required' => 'Choose a file to upload.',
        ];
    }
}
