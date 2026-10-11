<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\SettingResource;
use App\Models\Setting;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Platform settings (spec §20).
 *
 * Settings are declared by the seeder, not by this API: the table is a place to
 * store configuration the platform already knows it has, and letting the console
 * invent arbitrary keys would turn it into a scratchpad that nothing reads. The
 * `exists` rule on the key is what enforces that.
 *
 * Every write is audited with the old value beside the new one, because a
 * changed setting is invisible until something behaves differently, and by then
 * nobody remembers who changed it.
 */
class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every setting, grouped the way the settings screen shows them.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::query()
            ->orderBy('group')
            ->orderBy('label')
            ->get();

        $grouped = $settings
            ->groupBy('group')
            ->map(fn ($group, string $name): array => [
                'group' => $name,
                'label' => ucfirst($name),
                'settings' => SettingResource::collection($group)->resolve(),
            ])
            ->values();

        return response()->json([
            'groups' => $grouped,
            'settings' => SettingResource::collection($settings)->resolve(),
        ]);
    }

    /**
     * Save the settings the form submitted.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $submitted = $request->values();
        // Resolved once: the same models are read again after the write to
        // report what the settings now hold, and re-querying would be three
        // reads of the same rows in one request.
        $settings = $request->settings();
        $before = [];

        $changed = DB::transaction(function () use ($settings, $submitted, &$before): int {
            $count = 0;

            foreach ($settings as $setting) {
                $before[$setting->key] = $setting->value();

                // Cast by the setting's declared type, so a checkbox that
                // arrives as "on" is stored as the boolean it means.
                $setting->setValue($this->cast($submitted[$setting->key] ?? null, $setting->type));

                if ($setting->isDirty('value')) {
                    $setting->save();
                    $count++;
                }
            }

            return $count;
        });

        if ($changed > 0) {
            $after = [];

            foreach ($settings as $setting) {
                $after[$setting->key] = $setting->value();
            }

            $this->audit->recordChange(
                AdminAction::SettingUpdated,
                null,
                $before,
                $after,
                sprintf('Updated %d platform %s', $changed, $changed === 1 ? 'setting' : 'settings'),
            );
        }

        return response()->json([
            'updated' => $changed,
            'settings' => SettingResource::collection($settings)->resolve(),
        ]);
    }

    /**
     * Coerces a submitted value to the setting's declared type.
     *
     * The form sends strings; the setting knows what it is. An unchecked
     * checkbox does not arrive at all, which is why a boolean with no value is
     * false rather than null — an absent checkbox means "off", not "unset".
     */
    private function cast(mixed $value, string $type): string|int|bool|array|null
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => $value === null || $value === '' ? null : (int) $value,
            'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => $value === null ? null : (string) $value,
        };
    }
}
