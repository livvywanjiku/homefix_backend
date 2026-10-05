<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the service catalogue and the location list.
 *
 * Idempotent and safe to re-run: everything is keyed on its slug or its
 * name/region pair, so re-seeding updates rather than duplicating. That matters
 * because this seeder runs on every `migrate:fresh --seed` and an operator may
 * re-run it after editing the lists.
 */
class CatalogueSeeder extends Seeder
{
    /**
     * The catalogue from spec §5: fifteen categories, each with the services
     * customers actually search for. The first service of each category is the
     * one most often requested, which is why the lists are ordered rather than
     * alphabetical.
     *
     * @var array<string, array{icon: string, services: array<int, string>}>
     */
    private const CATALOGUE = [
        'Plumbing' => ['icon' => '🔧', 'services' => [
            'Leak Repair', 'Pipe Installation', 'Drain Cleaning', 'Water Heater Repair', 'Toilet Repair',
        ]],
        'Electrical' => ['icon' => '⚡', 'services' => [
            'Wiring Repair', 'Socket & Switch Installation', 'Lighting Installation', 'Fuse Box Repair', 'Generator Installation',
        ]],
        'Cleaning' => ['icon' => '🧹', 'services' => [
            'House Cleaning', 'Deep Cleaning', 'Office Cleaning', 'Carpet Cleaning', 'Post-Construction Cleaning',
        ]],
        'Carpentry' => ['icon' => '🪚', 'services' => [
            'Furniture Repair', 'Custom Cabinets', 'Door Installation', 'Wardrobe Fitting', 'Window Frames',
        ]],
        'Painting' => ['icon' => '🎨', 'services' => [
            'Interior Painting', 'Exterior Painting', 'Wall Texturing', 'Roof Painting', 'Fence Painting',
        ]],
        'Gardening' => ['icon' => '🌿', 'services' => [
            'Lawn Mowing', 'Hedge Trimming', 'Tree Pruning', 'Garden Design', 'Landscaping',
        ]],
        'Appliance Repair' => ['icon' => '🔌', 'services' => [
            'Refrigerator Repair', 'Washing Machine Repair', 'Oven Repair', 'Microwave Repair', 'Cooker Repair',
        ]],
        'Masonry' => ['icon' => '🧱', 'services' => [
            'Wall Construction', 'Plastering', 'Tiling', 'Concrete Works', 'Foundation Repair',
        ]],
        'Roofing' => ['icon' => '🏠', 'services' => [
            'Roof Leak Repair', 'Roof Replacement', 'Gutter Installation', 'Ceiling Repair', 'Roof Inspection',
        ]],
        'Pest Control' => ['icon' => '🐜', 'services' => [
            'Fumigation', 'Rodent Control', 'Termite Treatment', 'Bed Bug Treatment', 'Cockroach Control',
        ]],
        'Moving' => ['icon' => '📦', 'services' => [
            'House Moving', 'Office Relocation', 'Furniture Transport', 'Packing Service', 'Storage',
        ]],
        'Laundry' => ['icon' => '🧺', 'services' => [
            'Wash & Fold', 'Dry Cleaning', 'Ironing', 'Curtain Cleaning', 'Sofa Cleaning',
        ]],
        'Air Conditioning' => ['icon' => '❄️', 'services' => [
            'AC Installation', 'AC Repair', 'AC Servicing', 'AC Gas Refill', 'Ventilation Installation',
        ]],
        'Security Systems' => ['icon' => '🔒', 'services' => [
            'CCTV Installation', 'Alarm Systems', 'Electric Fence', 'Access Control', 'Intercom Systems',
        ]],
        'General Maintenance' => ['icon' => '🛠️', 'services' => [
            'Handyman Services', 'Door Repair', 'Lock Replacement', 'Gutter Cleaning', 'Odd Jobs',
        ]],
    ];

    /**
     * Towns across Kenya, with coordinates for distance search (spec §11).
     *
     * Nakuru and its neighbours come first because the spec's worked examples
     * are set there; the rest give the search enough spread to be meaningful.
     *
     * @var array<int, array{0: string, 1: string, 2: float, 3: float}>
     */
    private const LOCATIONS = [
        ['Nakuru', 'Nakuru County', -0.3031, 36.0800],
        ['Njoro', 'Nakuru County', -0.3333, 35.9500],
        ['Naivasha', 'Nakuru County', -0.7167, 36.4333],
        ['Gilgil', 'Nakuru County', -0.4983, 36.3200],
        ['Nairobi', 'Nairobi County', -1.2864, 36.8172],
        ['Mombasa', 'Mombasa County', -4.0435, 39.6682],
        ['Kisumu', 'Kisumu County', -0.0917, 34.7680],
        ['Eldoret', 'Uasin Gishu County', 0.5143, 35.2698],
        ['Thika', 'Kiambu County', -1.0333, 37.0693],
        ['Nyeri', 'Nyeri County', -0.4167, 36.9500],
        ['Machakos', 'Machakos County', -1.5177, 37.2634],
        ['Kitale', 'Trans Nzoia County', 1.0157, 35.0062],
        ['Meru', 'Meru County', 0.0500, 37.6500],
        ['Kericho', 'Kericho County', -0.3667, 35.2833],
        ['Nanyuki', 'Laikipia County', 0.0167, 37.0667],
    ];

    public function run(): void
    {
        $this->seedCatalogue();
        $this->seedLocations();
    }

    private function seedCatalogue(): void
    {
        $sortOrder = 0;

        foreach (self::CATALOGUE as $name => $definition) {
            $category = ServiceCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'icon' => $definition['icon'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ],
            );

            foreach ($definition['services'] as $serviceName) {
                Service::updateOrCreate(
                    ['slug' => Str::slug($serviceName)],
                    [
                        'service_category_id' => $category->id,
                        'name' => $serviceName,
                        'is_active' => true,
                    ],
                );
            }

            $sortOrder++;
        }
    }

    private function seedLocations(): void
    {
        foreach (self::LOCATIONS as [$name, $region, $latitude, $longitude]) {
            Location::updateOrCreate(
                ['name' => $name, 'region' => $region],
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_active' => true,
                ],
            );
        }
    }
}
