<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to a portfolio item.
 */
#[Fillable(['portfolio_item_id', 'path', 'caption', 'is_cover', 'sort_order'])]
class PortfolioImage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(PortfolioItem::class);
    }
}
