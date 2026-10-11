<?php

namespace App\Http\Controllers\Api\Discovery;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalProfileResource;
use App\Models\ProfessionalProfile;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A single professional's public page (spec §14).
 *
 * Read-only and reachable without an account, because this is the page that
 * decides whether a visitor signs up. It shows the trading identity — business
 * name, about, services and prices, working hours, service areas, portfolio and
 * reputation — and nothing about the person behind it that the platform has not
 * chosen to publish.
 */
class PublicProfessionalController extends Controller
{
    public function show(ProfessionalProfile $professional): ProfessionalProfileResource
    {
        /*
         * 404, not 403, when the profile is not visible. An applicant's page is
         * private while it is under review, and a 403 would confirm that the id
         * exists — turning the directory into a way to enumerate who has applied.
         */
        abort_unless(Gate::allows('view', $professional), 404);

        return ProfessionalProfileResource::make($professional->load([
            // The public identity: a trading name and an avatar, never an email
            // or a phone number — see the resource, which withholds those unless
            // the viewer is the owner or an administrator.
            'user:id,name,avatar_path',
            'baseLocation',
            'serviceAreas',
            'services.service.category',
            'availabilities' => fn ($query) => $query->ordered(),
            'availabilityExceptions' => fn ($query) => $query->upcoming(),
            'portfolioItems' => fn ($query) => $query->ordered()->with('images'),
        ]))->additional([
            /*
             * The page carries a "Request this service" call to action, and a
             * guest must be sent to sign in rather than shown a button that
             * fails. This is the server's own answer to "could this viewer post
             * a request" — the frontend still cannot enforce it, and Laravel
             * rejects the call regardless of what the browser believes.
             */
            'meta' => [
                'can_request_service' => Gate::allows('create', ServiceRequest::class),
            ],
        ]);
    }
}
