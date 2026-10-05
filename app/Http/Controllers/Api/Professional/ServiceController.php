<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StoreProfessionalServiceRequest;
use App\Http\Requests\Professional\UpdateProfessionalServiceRequest;
use App\Http\Resources\ProfessionalServiceResource;
use App\Models\ProfessionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The catalogue services a professional offers, with their own pricing.
 */
class ServiceController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function index(Request $request): AnonymousResourceCollection
    {
        $offerings = $this->profile($request->user())
            ->services()
            ->with('service.category')
            ->orderBy('id')
            ->get();

        return ProfessionalServiceResource::collection($offerings);
    }

    public function store(StoreProfessionalServiceRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $data = $request->validated();

        /*
         * Checked here rather than left to the unique index: a constraint
         * violation surfaces as a 500 with a database message, while this is a
         * 422 the form can attach to the field the professional is looking at.
         */
        if ($profile->services()->where('service_id', $data['service_id'])->exists()) {
            throw ValidationException::withMessages([
                'service_id' => 'You have already listed this service — edit that one instead.',
            ]);
        }

        $offering = $profile->services()->create([
            'service_id' => $data['service_id'],
            'description' => $data['description'] ?? null,
            'pricing_type' => $data['pricing_type'],
            'price_min_cents' => $data['price_min_cents'] ?? null,
            'price_max_cents' => $data['price_max_cents'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return ProfessionalServiceResource::make($offering->load('service.category'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateProfessionalServiceRequest $request,
        ProfessionalService $offering,
    ): ProfessionalServiceResource {
        Gate::authorize('update', $offering);

        $offering->update($request->validated());

        return ProfessionalServiceResource::make($offering->load('service.category'));
    }

    public function destroy(ProfessionalService $offering): Response
    {
        Gate::authorize('delete', $offering);

        $offering->delete();

        return response()->noContent();
    }
}
