<?php

namespace App\Http\Resources;

use App\Models\QuotationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One costed line of a quotation (spec §9).
 *
 * `total_cents` is sent even though a client could multiply the two fields
 * itself, because it is the number the platform stands behind — rounding a
 * part-unit quantity is exactly the kind of arithmetic that goes wrong in a
 * browser, and a breakdown whose lines do not sum to its headline is worse than
 * no breakdown.
 *
 * @mixin QuotationItem
 */
class QuotationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'category' => $this->category->value,
            'category_label' => $this->category->label(),

            'description' => $this->description,

            // Cast to a float for the client: `decimal:2` hands back a fixed-point
            // string, and "2.50" arriving where a number is expected is a bug
            // waiting in somebody's formatter.
            'quantity' => (float) $this->quantity,

            'unit_price_cents' => $this->unit_price_cents,
            'total_cents' => $this->total_cents,
        ];
    }
}
