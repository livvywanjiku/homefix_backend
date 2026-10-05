<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Blocking a day that has already passed changes nothing, so it is
            // rejected rather than silently stored.
            'blocked_on' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blocked_on.after_or_equal' => 'Choose today or a future date.',
        ];
    }
}
