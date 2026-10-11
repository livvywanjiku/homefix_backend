<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceCategoryRequest;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceCategoryRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The service catalogue (spec §20).
 *
 * Categories and services are managed together because they cannot be managed
 * apart: a category with no services is a dead entry on the public grid, and a
 * service with no category cannot be browsed or filtered to. Every write here is
 * recorded, because the catalogue is what the public site shows and an
 * unexplained change to it is indistinguishable from a defacement.
 */
class CatalogueAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every category, including inactive ones.
     *
     * The public endpoint filters to active; the console has to be able to see
     * what it has switched off, or the switch is one-way.
     */
    public function categories(): AnonymousResourceCollection
    {
        return ServiceCategoryResource::collection(
            ServiceCategory::query()
                ->withCount('services')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function storeCategory(StoreServiceCategoryRequest $request): JsonResponse
    {
        $category = ServiceCategory::create($request->categoryData());

        $this->audit->record(
            AdminAction::CategoryCreated,
            $category,
            "Created the {$category->name} category",
        );

        return response()->json(['category' => ServiceCategoryResource::make($category)], 201);
    }

    public function updateCategory(
        UpdateServiceCategoryRequest $request,
        ServiceCategory $category,
    ): JsonResponse {
        $before = $category->only(array_keys($request->validated()));

        $category->fill($request->validated())->save();

        $this->audit->recordChange(
            AdminAction::CategoryUpdated,
            $category,
            $before,
            $request->validated(),
            "Updated the {$category->name} category",
        );

        return response()->json(['category' => ServiceCategoryResource::make($category)]);
    }

    /**
     * Retire a category.
     *
     * Refused while it still has services: `services.service_category_id`
     * cascades on delete, so removing a category would silently take its whole
     * catalogue with it — including services professionals are actively pricing
     * against. Deactivating is offered as the reversible alternative and named
     * in the error, so the administrator is told what to do rather than only
     * what not to.
     */
    public function destroyCategory(ServiceCategory $category): JsonResponse
    {
        $services = $category->services()->count();

        if ($services > 0) {
            throw ValidationException::withMessages([
                'category' => sprintf(
                    'This category still has %d %s. Deactivate it instead, or move them first.',
                    $services,
                    $services === 1 ? 'service' : 'services',
                ),
            ]);
        }

        $name = $category->name;
        $category->delete();

        $this->audit->record(AdminAction::CategoryDeleted, null, "Deleted the {$name} category");

        return response()->json(['deleted' => true]);
    }

    /**
     * Every service, with its category and how much demand it has drawn.
     */
    public function services(\Illuminate\Http\Request $request): AnonymousResourceCollection
    {
        $services = Service::query()
            ->with('category:id,name,slug')
            ->withCount('professionalServices')
            ->when(
                $request->integer('category_id'),
                fn ($query, int $category) => $query->where('service_category_id', $category),
            )
            ->when($request->filled('q'), function ($query) use ($request): void {
                $like = '%'.$request->string('q').'%';

                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ServiceResource::collection($services);
    }

    public function storeService(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::create($request->serviceData());

        $this->audit->record(
            AdminAction::ServiceCreated,
            $service,
            "Added {$service->name} to the catalogue",
        );

        return response()->json(['service' => ServiceResource::make($service->load('category'))], 201);
    }

    public function updateService(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $before = $service->only(array_keys($request->validated()));

        $service->fill($request->validated())->save();

        $this->audit->recordChange(
            AdminAction::ServiceUpdated,
            $service,
            $before,
            $request->validated(),
            "Updated {$service->name}",
        );

        return response()->json(['service' => ServiceResource::make($service->load('category'))]);
    }

    /**
     * Retire a service.
     *
     * Refused once professionals have priced against it. Their offerings and the
     * customers' historical requests both point at this row, and a deletion
     * would leave those records naming a service that no longer exists — so a
     * service with any history is deactivated rather than removed.
     */
    public function destroyService(Service $service): JsonResponse
    {
        $offerings = $service->professionalServices()->count();

        if ($offerings > 0) {
            throw ValidationException::withMessages([
                'service' => sprintf(
                    'This service is offered by %d %s and cannot be deleted. Deactivate it instead.',
                    $offerings,
                    $offerings === 1 ? 'professional' : 'professionals',
                ),
            ]);
        }

        $name = $service->name;
        $service->delete();

        $this->audit->record(AdminAction::ServiceDeleted, null, "Deleted {$name} from the catalogue");

        return response()->json(['deleted' => true]);
    }
}
