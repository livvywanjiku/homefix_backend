<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StorePortfolioImageRequest;
use App\Http\Requests\Professional\StorePortfolioItemRequest;
use App\Http\Requests\Professional\UpdatePortfolioItemRequest;
use App\Http\Resources\PortfolioImageResource;
use App\Http\Resources\PortfolioItemResource;
use App\Models\PortfolioImage;
use App\Models\PortfolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A professional's public gallery of finished work (spec §12).
 */
class PortfolioController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->profile($request->user())
            ->portfolioItems()
            ->ordered()
            ->with(['images', 'service.category', 'location'])
            ->get();

        return PortfolioItemResource::collection($items);
    }

    public function store(StorePortfolioItemRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $data = $request->validated();

        $item = DB::transaction(function () use ($profile, $data, $request): PortfolioItem {
            $item = $profile->portfolioItems()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'completed_on' => $data['completed_on'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_published' => $data['is_published'] ?? true,
            ]);

            $this->storeImages($item, $request->file('images') ?? []);

            return $item;
        });

        return PortfolioItemResource::make($this->loadForDisplay($item))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePortfolioItemRequest $request, PortfolioItem $item): PortfolioItemResource
    {
        Gate::authorize('update', $item);

        $item->update($request->validated());

        return PortfolioItemResource::make($this->loadForDisplay($item));
    }

    public function destroy(PortfolioItem $item): Response
    {
        Gate::authorize('delete', $item);

        /*
         * The image rows cascade with the item, but the files on disk do not.
         * They are read off the model before the delete, while the paths are
         * still available.
         */
        Storage::disk('public')->delete($item->images->pluck('path')->all());

        $item->delete();

        return response()->noContent();
    }

    /**
     * Add photos to an item that already exists.
     */
    public function uploadImages(
        StorePortfolioImageRequest $request,
        PortfolioItem $item,
    ): JsonResponse {
        Gate::authorize('update', $item);

        $images = DB::transaction(function () use ($request, $item) {
            $this->storeImages($item, $request->file('images'));

            return $item->images()->orderBy('sort_order')->get();
        });

        return PortfolioImageResource::collection($images)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyImage(PortfolioItem $item, PortfolioImage $image): Response
    {
        Gate::authorize('update', $item);

        // Scoped to the item so an image id belonging to another professional's
        // gallery cannot be deleted through this route.
        abort_unless($image->portfolio_item_id === $item->id, 404);

        $wasCover = $image->is_cover;

        Storage::disk('public')->delete($image->path);
        $image->delete();

        // Removing the cover would otherwise leave the gallery card blank, so
        // the next photo in order is promoted.
        if ($wasCover) {
            $item->images()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
        }

        return response()->noContent();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeImages(PortfolioItem $item, array $files): void
    {
        if ($files === []) {
            return;
        }

        // Appended after what is already there so adding a photo never
        // reshuffles the gallery the professional has arranged.
        $nextOrder = (int) $item->images()->max('sort_order');
        $hasCover = $item->images()->where('is_cover', true)->exists();

        foreach (array_values($files) as $index => $file) {
            $item->images()->create([
                'path' => $file->store("portfolio/{$item->professional_profile_id}", 'public'),
                'caption' => null,
                'is_cover' => ! $hasCover && $index === 0,
                'sort_order' => $nextOrder + $index + 1,
            ]);
        }
    }

    private function loadForDisplay(PortfolioItem $item): PortfolioItem
    {
        return $item->load(['images', 'service.category', 'location']);
    }
}
