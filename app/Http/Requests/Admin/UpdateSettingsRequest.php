<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving platform settings (spec §20).
 *
 * A partial write: only the keys present are touched, so one screen saving its
 * own group cannot blank another screen's values. Keys must already exist —
 * settings are declared by the seeder, and letting the API invent new ones would
 * make the settings table a place to hide arbitrary data.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => [
                'required',
                'string',
                'max:100',
                Rule::exists('settings', 'key'),
            ],
            // Deliberately untyped: a setting's value is cast by its declared
            // `type` column, so a boolean arrives as a boolean and a number as a
            // number. Constraining it here would only duplicate that rule.
            'settings.*.value' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.*.key.exists' => 'That setting does not exist.',
        ];
    }

    /**
     * The submitted key/value pairs, keyed for lookup.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->validated('settings') as $setting) {
            $values[$setting['key']] = $setting['value'] ?? null;
        }

        return $values;
    }

    /**
     * The settings being written, so the controller can cast against their type.
     *
     * @return \Illuminate\Support\Collection<int, Setting>
     */
    public function settings(): \Illuminate\Support\Collection
    {
        return Setting::query()->whereIn('key', array_keys($this->values()))->get();
    }
}
