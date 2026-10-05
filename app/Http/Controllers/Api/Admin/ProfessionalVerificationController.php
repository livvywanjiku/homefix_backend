<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\ReviewProfessionalRequest;
use App\Http\Resources\ProfessionalDocumentResource;
use App\Http\Resources\ProfessionalProfileResource;
use App\Models\ProfessionalDocument;
use App\Models\ProfessionalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The verification queue (spec §19, §20).
 *
 * Route middleware requires the `verify_professional` permission; the model
 * policies separately confirm the reviewer is acting as an administrator.
 */
class ProfessionalVerificationController extends Controller
{
    /**
     * Applications awaiting a decision, or filtered to one status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        $profiles = ProfessionalProfile::query()
            ->with(['user:id,name,email,phone,avatar_path', 'baseLocation', 'documents'])
            ->withCount(['services', 'documents'])
            ->when(
                $status !== null,
                fn ($query) => $query->where('verification_status', $status),

                // The default view is the work still to do: applications
                // waiting, plus ones sent back for correction. Verified and
                // suspended profiles are reachable by asking for them.
                fn ($query) => $query->whereIn('verification_status', [
                    VerificationStatus::Pending->value,
                    VerificationStatus::Rejected->value,
                ]),
            )
            ->orderBy('updated_at')
            ->paginate(20)
            ->withQueryString();

        return ProfessionalProfileResource::collection($profiles);
    }

    /**
     * Verify, reject or suspend a professional.
     */
    public function review(
        ReviewProfessionalRequest $request,
        ProfessionalProfile $professional,
    ): ProfessionalProfileResource {
        $data = $request->validated();

        switch ($data['decision']) {
            case 'verify':
                $professional->forceFill([
                    'verification_status' => VerificationStatus::Verified,
                    'verified_at' => now(),
                    'verification_notes' => $data['notes'] ?? null,
                ])->save();
                break;

            case 'reject':
                $professional->forceFill([
                    'verification_status' => VerificationStatus::Rejected,
                    // Cleared because a rejected profile was never verified;
                    // a stale timestamp would misreport when it was approved.
                    'verified_at' => null,
                    'verification_notes' => $data['notes'],
                ])->save();
                break;

            case 'suspend':
                $professional->forceFill([
                    'verification_status' => VerificationStatus::Suspended,
                    // `verified_at` is left as it stands: a suspension records
                    // that the professional *was* verified and has since been
                    // stood down, which is the history support needs.
                    'verification_notes' => $data['notes'],
                ])->save();
                break;
        }

        return ProfessionalProfileResource::make(
            $professional->load(['user:id,name,email,phone,avatar_path', 'baseLocation', 'documents']),
        );
    }

    /**
     * Approve or reject one uploaded document.
     */
    public function reviewDocument(
        ReviewDocumentRequest $request,
        ProfessionalDocument $document,
    ): ProfessionalDocumentResource {
        Gate::authorize('review', $document);

        $reviewer = $request->user();

        if ($request->validated('decision') === 'approve') {
            $document->approve($reviewer);
        } else {
            $document->reject($reviewer, $request->validated('notes'));
        }

        return ProfessionalDocumentResource::make($document->load('professionalProfile:id,user_id'));
    }
}
