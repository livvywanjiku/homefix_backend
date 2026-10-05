<?php

namespace App\Http\Requests\Professional;

use App\Enums\PricingType;
use App\Http\Requests\Professional\Concerns\ValidatesServicePricing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfessionalServiceRequest extends FormRequest
{
    use ValidatesServicePricing;

    protected function prepareForValidation(): void
    {
        $this->normaliseQuotePricing();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only an active catalogue service may be offered — a retired one
            // must not become selectable through a hand-crafted request.
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('is_active', true),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'pricing_type' => ['required', Rule::enum(PricingType::class)],

            /*
             * Prices arrive as integer minor units (cents), never as a decimal
             * string — the frontend sends what the professional typed in
             * shillings multiplied by 100, so no float ever touches the value.
             */
            'price_min_cents' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'price_max_cents' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateServicePricing($validator);
    }
}
