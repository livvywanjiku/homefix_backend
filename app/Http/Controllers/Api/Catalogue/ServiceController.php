<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\ListServicesRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The flat list of bookable services.
 *
 * Where the category endpoints answer "what does HomeFix offer?", this answers
 * "which service is the customer about to request?" — it backs the picker in
 * the request wizard, which needs every service at once, optionally narrowed
 * to one category.
 */
class ServiceController extends Controller
{
    public function index(ListServicesRequest $request): AnonymousResourceCollection
    {
        $services = Service::query()
            ->active()
            ->with('category')
            ->when(
                $request->validated('category'),
                fn ($query, string $slug) => $query->whereHas(
                    'category',
                    fn ($category) => $category->where('slug', $slug)->where('is_active', true),
                ),
            )
            ->when(
                $request->validated('q'),
                // Case-insensitive on both PostgreSQL and SQLite, which a raw
                // LIKE would not be (SQLite's LIKE is only case-insensitive for
                // ASCII, and PostgreSQL's is case-sensitive outright).
                fn ($query, string $term) => $query->whereLike('name', "%{$term}%", caseSensitive: false),
            )
            ->orderBy('name')
            ->get();

        return ServiceResource::collection($services);
    }
}
