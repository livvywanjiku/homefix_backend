<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StoreAvailabilityExceptionRequest;
use App\Http\Requests\Professional\UpdateAvailabilityRequest;
use App\Http\Resources\AvailabilityExceptionResource;
use App\Http\Resources\AvailabilityResource;
use App\Models\AvailabilityException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The weekly working pattern and the dates blocked out of it (spec §18).
 */
class AvailabilityController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());

        return response()->json([
            'data' => [
                'days' => AvailabilityResource::collection(
                    $profile->availabilities()->ordered()->get(),
                )->resolve($request),
                'exceptions' => AvailabilityExceptionResource::collection(
                    $profile->availabilityExceptions()->upcoming()->orderBy('blocked_on')->get(),
                )->resolve($request),
            ],
        ]);
    }

    /**
     * Replace the whole week in one call.
     */
    public function update(UpdateAvailabilityRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());

        $days = array_map(fn (array $day): array => [
            'day_of_week' => $day['day_of_week'],
            'start_time' => $day['start_time'],
            'end_time' => $day['end_time'],
        ], $request->validated('days'));

        DB::transaction(function () use ($profile, $days): void {
            /*
             * Replace rather than upsert: the editor sends the complete week,
             * so a day the professional cleared must actually disappear. An
             * upsert would quietly leave a day they thought they had removed.
             */
            $profile->availabilities()->delete();

            if ($days !== []) {
                $profile->availabilities()->createMany($days);
            }
        });

        return response()->json([
            'data' => AvailabilityResource::collection(
                $profile->availabilities()->ordered()->get(),
            )->resolve($request),
        ]);
    }

    /**
     * Block a single date.
     */
    public function storeException(StoreAvailabilityExceptionRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $data = $request->validated();

        /*
         * Matched with `whereDate` rather than a plain equality, because the
         * column carries a date cast: the value as stored is a full timestamp
         * while the request supplies a bare date, and comparing the two
         * directly would miss the existing row and trip the unique index.
         */
        $exception = $profile->availabilityExceptions()
            ->whereDate('blocked_on', $data['blocked_on'])
            ->first();

        if ($exception === null) {
            $exception = $profile->availabilityExceptions()->create([
                'blocked_on' => $data['blocked_on'],
                'reason' => $data['reason'] ?? null,
            ]);

            return AvailabilityExceptionResource::make($exception)
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        // Blocking a date that is already blocked only updates the reason —
        // from the professional's side they are just saving the same day twice.
        $exception->update(['reason' => $data['reason'] ?? null]);

        return AvailabilityExceptionResource::make($exception)->response();
    }

    public function destroyException(AvailabilityException $exception): Response
    {
        Gate::authorize('delete', $exception);

        $exception->delete();

        return response()->noContent();
    }
}
