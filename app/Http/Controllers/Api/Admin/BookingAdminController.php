<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListBookingsRequest;
use App\Http\Requests\Admin\TransitionBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\Admin\AuditLogger;
use App\Services\Bookings\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every piece of agreed work (spec §20).
 *
 * The list is the platform's operational picture: what is scheduled, what is
 * stuck, what was cancelled and by whom. The one write is the dispute
 * resolution, and it goes through {@see BookingService} rather than around it —
 * an administrator arbitrating a dispute is subject to the same state machine as
 * everybody else, which is the only way the machine stays meaningful.
 */
class BookingAdminController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The booking list, filtered and paginated.
     */
    public function index(ListBookingsRequest $request): AnonymousResourceCollection
    {
        $bookings = Booking::query()
            ->forDisplay()
            ->when(
                $request->validated('status'),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $request->validated('service_id'),
                fn ($query, int $service) => $query->where('service_id', $service),
            )
            ->when(
                $request->validated('location_id'),
                fn ($query, int $location) => $query->where('location_id', $location),
            )
            ->when($request->validated('q'), function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('reference', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhereHas('customer', fn ($user) => $user->where('name', 'like', $like))
                        ->orWhereHas('professional', fn ($user) => $user->where('name', 'like', $like));
                });
            })
            ->when(
                $request->validated('sort'),
                fn ($query, string $sort) => match ($sort) {
                    'oldest' => $query->orderBy('created_at'),
                    'scheduled' => $query->orderBy('scheduled_for'),
                    'value' => $query->orderByDesc('total_cents'),
                    default => $query->orderByDesc('created_at'),
                },
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    /**
     * One booking, with its full state history.
     */
    public function show(Booking $booking): JsonResponse
    {
        $booking->load([
            'service:id,name,slug,service_category_id',
            'location:id,name,region',
            'customer:id,name,email,phone,avatar_path',
            'professional:id,name,email,phone,avatar_path',
            'quotation:id,reference,total_cents,status',
            'statusHistory.changedBy:id,name',
        ]);

        return response()->json([
            'booking' => BookingResource::make($booking),
            /*
             * The history is what an administrator actually reads when a dispute
             * is open: who moved it, when, and what they said at the time. The
             * status column alone cannot answer "who cancelled this".
             */
            'history' => $booking->statusHistory->map(fn ($entry): array => [
                'id' => $entry->id,
                'from' => $entry->from_status?->value,
                'from_label' => $entry->from_status?->label(),
                'to' => $entry->to_status->value,
                'to_label' => $entry->to_status->label(),
                'note' => $entry->note,
                'changed_by' => $entry->changedBy?->name,
                'at' => $entry->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Move a booking to another state, as a dispute resolution or a correction.
     */
    public function transition(TransitionBookingRequest $request, Booking $booking): JsonResponse
    {
        $target = $request->target();
        $note = $request->validated('note');

        $updated = $this->bookings->transition(
            $booking,
            $target,
            $request->user(),
            // Attributed in the history as an administrative act, so a reader
            // can tell a resolution from an ordinary advance.
            $note === null ? 'Changed by an administrator.' : $note,
        );

        $this->audit->record(
            AdminAction::BookingUpdated,
            $updated,
            sprintf('Moved %s to %s', $updated->reference, strtolower($target->label())),
            ['to' => $target->value],
        );

        return response()->json(['booking' => BookingResource::make($updated->load([
            'service:id,name,slug',
            'location:id,name,region',
            'customer:id,name,avatar_path',
            'professional:id,name,avatar_path',
        ]))]);
    }

    /**
     * The states a booking in its current position could move to.
     *
     * Served so the console offers only legal moves. The frontend is not trusted
     * to respect it — {@see BookingService::transition()} refuses an illegal move
     * whatever the client sends — but a screen that offers a button guaranteed
     * to fail is a screen that teaches people not to trust it.
     */
    public function transitions(Booking $booking): JsonResponse
    {
        return response()->json([
            'current' => $booking->status->value,
            'allowed' => array_map(
                fn (BookingStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                $booking->status->allowedTransitions(),
            ),
        ]);
    }
}
