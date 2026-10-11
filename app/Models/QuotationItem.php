<?php

namespace App\Models;

use App\Enums\QuotationItemCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One costed line of a quotation (spec §9).
 *
 * `total_cents` is absent from the fillable list and computed on the way in.
 * A line whose arithmetic is supplied by the client is a line the platform
 * cannot trust, and a quotation built from untrusted arithmetic is worse than no
 * quotation — the customer would be comparing figures that do not add up.
 */
#[Fillable([
    'category',
    'description',
    'quantity',
    'unit_price_cents',
    'sort_order',
])]
class QuotationItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => QuotationItemCategory::class,
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Keeps the line total and its quotation's summary in step, automatically.
     *
     * Doing this through model events rather than asking each caller to remember
     * means the two invariants this table has — "a line's total is its own
     * arithmetic" and "a quotation's total is its lines" — hold no matter which
     * code path writes a line, including the seeder and future admin tooling.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->total_cents = $item->lineTotal();
        });

        static::saved(fn (self $item) => $item->quotation?->recalculateTotals());

        /*
         * A removed line changes the price just as much as an added one. Note
         * that a cascade delete of the whole quotation does not pass through
         * here, which is correct — recalculating a quotation that is being
         * deleted would resurrect it.
         */
        static::deleted(fn (self $item) => $item->quotation?->recalculateTotals());
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Quantity times unit price, in minor units, rounded to the nearest cent.
     *
     * The cast hands `quantity` back as a fixed-point string ("2.50"), so it is
     * read as a float here rather than trusted to multiply as one.
     */
    public function lineTotal(): int
    {
        return (int) round((float) $this->quantity * $this->unit_price_cents);
    }
}
