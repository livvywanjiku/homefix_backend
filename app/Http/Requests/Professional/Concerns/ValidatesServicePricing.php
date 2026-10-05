<?php

namespace App\Http\Requests\Professional\Concerns;

use App\Enums\PricingType;
use Illuminate\Contracts\Validation\Validator;

/**
 * The pricing rules shared by creating and editing an offering.
 *
 * Both actions accept the same three-field pricing shape, and the two checks
 * below are the parts a flat rule string cannot express — so they live here
 * once rather than being copy-pasted into each request and drifting apart.
 */
trait ValidatesServicePricing
{
    /**
     * A quote-on-inspection offering carries no figures at all.
     *
     * Normalising here rather than in the controller means the checks below
     * never have to reason about a client that sent a price alongside a quote
     * type, and the stored row cannot end up with a price it should not have.
     */
    protected function normaliseQuotePricing(): void
    {
        if ($this->input('pricing_type') === PricingType::Quote->value) {
            $this->merge([
                'price_min_cents' => null,
                'price_max_cents' => null,
            ]);
        }
    }

    /**
     * A floor price is required whenever the offering is not quote-only, and a
     * range must be the right way round.
     */
    protected function validateServicePricing(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isQuoteOnly = $this->input('pricing_type') === PricingType::Quote->value;
            $min = $this->input('price_min_cents');
            $max = $this->input('price_max_cents');

            if (! $isQuoteOnly && $min === null) {
                $validator->errors()->add(
                    'price_min_cents',
                    'Enter a price, or choose "quote on inspection".',
                );
            }

            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $validator->errors()->add(
                    'price_max_cents',
                    'The upper price must be at least the lower price.',
                );
            }
        });
    }
}
