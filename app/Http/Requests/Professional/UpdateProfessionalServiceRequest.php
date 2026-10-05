<?php

namespace App\Http\Requests\Professional;

use App\Enums\PricingType;
use App\Http\Requests\Professional\Concerns\ValidatesServicePricing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an offering.
 *
 * `service_id` is deliberately absent: which catalogue service an offering
 * represents is its identity. Changing it would silently turn "leak repair"
 * into "socket rewiring" — the professional deletes the offering and adds
 * another instead.
 */
class UpdateProfessionalServiceRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:1000'],
            'pricing_type' => ['required', Rule::enum(PricingType::class)],
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
