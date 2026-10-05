<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public service catalogue.
 *
 * Read-only and unauthenticated: a visitor has to be able to see what HomeFix
 * offers before deciding to create an account.
 */
class ServiceCategoryController extends Controller
{
    /**
     * Every active category, in display order.
     *
     * The whole catalogue is returned unpaginated — there are fifteen
     * categories by design, and the landing and services pages both render the
     * full set.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = ServiceCategory::query()
            ->active()
            ->ordered()
            // Counted in the query rather than loaded as a relation: the grid
            // needs "12 services", not the twelve rows themselves.
            ->withCount('services')
            ->get();

        return ServiceCategoryResource::collection($categories);
    }

    /**
     * A single category with its bookable services.
     */
    public function show(string $slug): ServiceCategoryResource
    {
        $category = ServiceCategory::query()
            ->active()
            ->where('slug', $slug)
            ->with(['services' => fn ($query) => $query->active()->orderBy('name')])
            ->firstOrFail();

        return ServiceCategoryResource::make($category);
    }
}
