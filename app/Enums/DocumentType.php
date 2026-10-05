<?php

namespace App\Enums;

/**
 * The kinds of evidence a professional submits for verification (spec §19).
 *
 * An enum rather than free text so the admin queue can group and filter by
 * document kind, and so a typo cannot create a category of one.
 */
enum DocumentType: string
{
    case NationalId = 'national_id';
    case Certificate = 'certificate';
    case License = 'license';
    case Insurance = 'insurance';
    case Other = 'other';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID or passport',
            self::Certificate => 'Trade certificate',
            self::License => 'Business licence',
            self::Insurance => 'Insurance cover',
            self::Other => 'Other supporting document',
        };
    }

    /**
     * Whether this document is required before an application can be
     * submitted. Without a verified identity there is nothing to verify, so
     * the ID is the one document the submission gate insists on.
     */
    public function isRequired(): bool
    {
        return $this === self::NationalId;
    }

    /**
     * The values that satisfy the required-document check.
     *
     * @return array<int, string>
     */
    public static function required(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->isRequired()),
        ));
    }
}
