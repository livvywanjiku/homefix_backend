<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequests\CancelServiceRequestRequest;
use App\Http\Requests\ServiceRequests\ListServiceRequestsRequest;
use App\Http\Requests\ServiceRequests\StoreServiceRequestImageRequest;
use App\Http\Requests\ServiceRequests\StoreServiceRequestRequest;
use App\Http\Requests\ServiceRequests\UpdateServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A customer's own job postings (spec §8).
 *
 * Every action resolves the customer from the authenticated account, so no route
 * here takes a customer id and there is no ownership check that can be forgotten.
 * Where a row is addressed by id, the policy confirms it belongs to the caller
 * before anything is read or written.
 */
class ServiceRequestController extends Controller
{
    /**
     * The customer's own requests.
     */
    public function index(ListServiceRequestsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServiceRequest::class);

        $filters = $request->validated();

        $requests = ServiceRequest::query()
            // Scoped to the caller before any filter is applied, so a status or
            // search filter can only ever narrow this account's rows.
            ->ownedBy($request->user())
            ->forDisplay()
            ->withCount('images')
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['urgency']),
                fn ($query) => $query->where('urgency', $filters['urgency']),
            )
            ->when(
                isset($filters['service_id']),
                fn ($query) => $query->where('service_id', $filters['service_id']),
            )
            ->when(
                isset($filters['q']),
                fn ($query) => $query->where(function ($q) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $q->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('reference', 'like', $term);
                }),
            )
            ->tap(fn ($query) => $this->applySort($query, $filters['sort'] ?? 'recent'))
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ServiceRequestResource::collection($requests);
    }

    public function store(StoreServiceRequestRequest $request): JsonResponse
    {
        Gate::authorize('create', ServiceRequest::class);

        $data = $request->validated();
        $files = $request->file('images') ?? [];

        /*
         * Files are written to disk before the transaction opens, and removed
         * again if the transaction fails. Storage is not transactional, so the
         * alternative is either orphaned rows pointing at missing files, or
         * orphaned files — this way the database stays authoritative and the
         * cleanup is explicit.
         */
        $stored = $this->storeFiles($files);

        try {
            $serviceRequest = DB::transaction(function () use ($request, $data, $stored): ServiceRequest {
                $serviceRequest = ServiceRequest::createFor([
                    ...$this->attributesFrom($data),
                    'customer_id' => $request->user()->id,
                ]);

                foreach ($stored as $order => $file) {
                    $serviceRequest->images()->create([
                        'path' => $file['path'],
                        'original_name' => $file['original_name'],
                        'size_bytes' => $file['size_bytes'],
                        'sort_order' => $order,
                    ]);
                }

                return $serviceRequest;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete(array_column($stored, 'path'));

            throw $exception;
        }

        return ServiceRequestResource::make($this->forDisplay($serviceRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        Gate::authorize('view', $serviceRequest);

        return ServiceRequestResource::make($this->forDisplay($serviceRequest));
    }

    public function update(
        UpdateServiceRequestRequest $request,
        ServiceRequest $serviceRequest,
    ): ServiceRequestResource {
        Gate::authorize('update', $serviceRequest);

        $serviceRequest->update($this->attributesFrom($request->validated()));

        return ServiceRequestResource::make($this->forDisplay($serviceRequest));
    }

    /**
     * Withdraw a posting.
     *
     * A status change rather than a delete: the professionals who quoted have a
     * record of what they priced, and a request that vanishes takes that with it.
     */
    public function cancel(
        CancelServiceRequestRequest $request,
        ServiceRequest $serviceRequest,
    ): ServiceRequestResource {
        Gate::authorize('cancel', $serviceRequest);

        $serviceRequest->cancel();

        return ServiceRequestResource::make($this->forDisplay($serviceRequest));
    }

    public function uploadImages(
        StoreServiceRequestImageRequest $request,
        ServiceRequest $serviceRequest,
    ): JsonResponse {
        Gate::authorize('manageImages', $serviceRequest);

        $existing = $serviceRequest->images()->count();
        $stored = $this->storeFiles($request->file('images') ?? []);

        /*
         * A cap on the total, not just on the batch: the request rule allows
         * five per call, and without this a customer could post five at a time
         * indefinitely and turn a job description into a photo dump.
         */
        $allowed = max(0, ServiceRequest::MAX_IMAGES - $existing);

        if (count($stored) > $allowed) {
            Storage::disk('public')->delete(array_column($stored, 'path'));

            return response()->json([
                'message' => $allowed === 0
                    ? 'This request already has the maximum of '.ServiceRequest::MAX_IMAGES.' photos.'
                    : "You can add {$allowed} more photo(s) to this request.",
                'errors' => ['images' => ['Too many photos.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::transaction(function () use ($serviceRequest, $stored, $existing): void {
                foreach ($stored as $offset => $file) {
                    $serviceRequest->images()->create([
                        'path' => $file['path'],
                        'original_name' => $file['original_name'],
                        'size_bytes' => $file['size_bytes'],
                        'sort_order' => $existing + $offset,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete(array_column($stored, 'path'));

            throw $exception;
        }

        return ServiceRequestResource::make($this->forDisplay($serviceRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyImage(
        ServiceRequest $serviceRequest,
        ServiceRequestImage $image,
    ): Response {
        Gate::authorize('manageImages', $serviceRequest);

        // The image must belong to the request in the path. Without this, a
        // customer could delete a photo off somebody else's request by pairing
        // their own request id with a foreign image id.
        abort_unless($image->service_request_id === $serviceRequest->id, Response::HTTP_NOT_FOUND);

        Storage::disk('public')->delete($image->path);

        $image->delete();

        return response()->noContent();
    }

    /**
     * The columns a customer may set, drawn from validated input only.
     *
     * Built explicitly rather than by passing `validated()` straight through, so
     * a field added to the request class for a different purpose cannot ride
     * along into the model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        return [
            'service_id' => $data['service_id'],
            'location_id' => $data['location_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'instructions' => $data['instructions'] ?? null,
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time_slot' => $data['preferred_time_slot'] ?? null,
            'budget_min_cents' => $data['budget_min_cents'] ?? null,
            'budget_max_cents' => $data['budget_max_cents'] ?? null,
            'urgency' => $data['urgency'],
        ];
    }

    /**
     * Writes uploads to the public disk under a per-customer folder.
     *
     * `store()` names each file randomly, so a customer cannot choose where a
     * file lands or overwrite another's.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return array<int, array{path: string, original_name: string, size_bytes: int}>
     */
    private function storeFiles(array $files): array
    {
        $stored = [];

        foreach ($files as $file) {
            $stored[] = [
                'path' => $file->store('service-requests', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
            ];
        }

        return $stored;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ServiceRequest>  $query
     */
    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest(),
            'quotes' => $query->orderByDesc('quotes_count')->latest(),
            // Soonest to lapse first, so a customer sees what needs attention.
            'expiring' => $query->orderByRaw('expires_at is null')->orderBy('expires_at'),
            default => $query->latest(),
        };
    }

    /**
     * The eager-load set every response here uses, plus the relations the
     * resource needs to render a customer's own view.
     */
    private function forDisplay(ServiceRequest $serviceRequest): ServiceRequest
    {
        return $serviceRequest->load([
            'service:id,name,slug,service_category_id',
            'service.category:id,name,slug',
            'location:id,name,region',
            'images',
        ]);
    }
}
