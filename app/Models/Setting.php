<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform setting (spec §20, "System settings").
 *
 * Values are stored as text with a `type` beside them, because a settings table
 * has to hold a string beside a number beside a flag. {@see value()} is the only
 * place that cast happens, so a caller never reads the raw column and guesses.
 */
#[Fillable(['key', 'value', 'type', 'group', 'is_public', 'label', 'description'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * The value, cast according to its declared type.
     *
     * A null column stays null rather than becoming "" — "not set" and "set to
     * empty" are different answers on a settings screen.
     */
    public function value(): string|int|bool|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true) ?? [],
            default => (string) $this->value,
        };
    }

    /**
     * Stores a value of any supported type as its text representation.
     */
    public function setValue(string|int|bool|array|null $value): void
    {
        $this->value = match (true) {
            $value === null => null,
            is_array($value) => json_encode($value),
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * The stored value for a key, or the given default.
     */
    public static function get(string $key, string|int|bool|array|null $default = null): string|int|bool|array|null
    {
        return static::query()->where('key', $key)->first()?->value() ?? $default;
    }

    /**
     * A whole-number setting.
     *
     * Falls back to the default when the stored value is missing or is not a
     * usable number — a setting an administrator has blanked must not become a
     * `days` count of null and expire everything the moment it is saved.
     */
    public static function int(string $key, int $default): int
    {
        $value = static::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    #[Scope]
    protected function inGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Only the settings the public site is allowed to read.
     *
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    #[Scope]
    protected function publiclyReadable(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}
