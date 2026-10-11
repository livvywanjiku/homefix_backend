<?php

namespace App\Enums;

/**
 * What kind of cost a quotation line represents (spec §9).
 *
 * The spec asks for labour, materials and transport to be broken out, and this
 * enum is that breakdown: a quotation's four cached totals are the sums of its
 * lines grouped by these cases. `Other` exists so a professional never has to
 * mis-file a cost to fit the list — a permit fee is neither labour nor materials,
 * and forcing it into one of them would make the breakdown lie.
 */
enum QuotationItemCategory: string
{
    case Labour = 'labour';
    case Materials = 'materials';
    case Transport = 'transport';
    case Other = 'other';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Labour => 'Labour',
            self::Materials => 'Materials',
            self::Transport => 'Transport',
            self::Other => 'Other',
        };
    }

    /**
     * Every category, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category): string => $category->value, self::cases());
    }
}
