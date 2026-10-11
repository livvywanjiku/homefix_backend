<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class CancelServiceRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Optional, but kept short. The reason is shown to the professionals
             * who quoted — it is courtesy, not a case file — and it is capped so
             * it cannot be used to paste an essay into every quoter's inbox.
             */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
