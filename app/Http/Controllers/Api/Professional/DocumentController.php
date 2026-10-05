<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StoreProfessionalDocumentRequest;
use App\Http\Resources\ProfessionalDocumentResource;
use App\Models\ProfessionalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The evidence a professional files for verification (spec §19).
 */
class DocumentController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = $this->profile($request->user())
            ->documents()
            ->with('professionalProfile:id,user_id')
            ->latest()
            ->get();

        return ProfessionalDocumentResource::collection($documents);
    }

    public function store(StoreProfessionalDocumentRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());

        /*
         * The stored path is derived from the upload, never from the request.
         * `store()` generates a random filename, so a client cannot choose
         * where its file lands or collide with another professional's.
         */
        $path = $request->file('document')->store("documents/{$profile->id}", 'public');

        $document = $profile->documents()->create([
            'type' => $request->validated('type'),
            'path' => $path,
        ]);

        /*
         * The profile's own status is left alone here. Adding evidence and
         * asking to be reviewed are two different acts, and only the second one
         * is a state transition — so an already-verified professional can file
         * an updated licence without dropping out of search while it is read.
         */
        return ProfessionalDocumentResource::make($document->load('professionalProfile:id,user_id'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(ProfessionalDocument $document): Response
    {
        Gate::authorize('delete', $document);

        Storage::disk('public')->delete($document->path);

        $document->delete();

        return response()->noContent();
    }
}
