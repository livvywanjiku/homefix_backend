<?php

namespace Database\Seeders;

use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * The platform settings the console can edit.
 *
 * Deliberately short. A settings table is only worth having where something
 * actually reads the value, so every row declared here is read by code that
 * exists: the two marketplace windows below are consulted whenever a service
 * request or a quotation is written, and the third row is the marker the role
 * console sets when an administrator edits a role's permissions.
 *
 * A key declared here and read by nothing would be a form field that appears to
 * configure the platform and does not, which is worse than not offering it.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => ServiceRequest::LIFETIME_SETTING,
                'value' => (string) ServiceRequest::LIFETIME_DAYS,
                'type' => 'integer',
                'group' => 'marketplace',
                'label' => 'Request lifetime (days)',
                'description' => 'How long a customer’s job posting stays on the board before it lapses. Applies to new postings; deadlines already published do not move.',
            ],
            [
                'key' => Quotation::VALIDITY_SETTING,
                'value' => (string) Quotation::DEFAULT_VALIDITY_DAYS,
                'type' => 'integer',
                'group' => 'marketplace',
                'label' => 'Quotation validity (days)',
                'description' => 'How long a professional’s price stands when they do not set their own deadline. Applies to quotations made after the change.',
            ],
        ];

        foreach ($settings as $setting) {
            /*
             * `firstOrCreate` rather than `updateOrCreate`: re-running the
             * seeder on a live database must not reset a value an administrator
             * has deliberately changed.
             */
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                $setting + ['is_public' => false],
            );
        }
    }
}
