<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The locations a customer can request work in.
 *
 * Public and unpaginated, like the service catalogue: the set is small, and
 * the pickers on the landing page, the request wizard and the professional
 * search all need the whole list.
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->active()
            ->when(
                $request->string('q')->trim()->value(),
                fn ($query, string $term) => $query->whereLike('name', "%{$term}%", caseSensitive: false),
            )
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }
}
