<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Http\Resources\AdminLogResource;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The audit trail (spec §20).
 *
 * Read-only, and there is no endpoint anywhere that deletes, edits or hides an
 * entry. A trail that can be amended by the people it records is not a trail.
 */
class AuditLogController extends Controller
{
    /**
     * The log, filtered and paginated.
     */
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        $logs = AdminLog::query()
            ->with('user:id,name')
            ->when(
                $request->validated('action'),
                fn ($query, string $action) => $query->forAction(\App\Enums\AdminAction::from($action)),
            )
            ->when(
                $request->validated('user_id'),
                fn ($query, int $user) => $query->where('user_id', $user),
            )
            ->when(
                $request->validated('subject_type'),
                fn ($query, string $type) => $query->where('subject_type', $type),
            )
            ->when(
                $request->validated('subject_id'),
                fn ($query, int $id) => $query->where('subject_id', $id),
            )
            ->when(
                $request->validated('from'),
                fn ($query, string $from) => $query->where('created_at', '>=', $from),
            )
            ->when(
                $request->validated('to'),
                fn ($query, string $to) => $query->where('created_at', '<=', $to),
            )
            ->when($request->validated('q'), function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(fn ($inner) => $inner
                    ->where('description', 'like', $like)
                    ->orWhere('ip_address', 'like', $like));
            })
            ->recent()
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return AdminLogResource::collection($logs);
    }

    /**
     * One entry, in full.
     */
    public function show(AdminLog $auditLog): JsonResponse
    {
        return response()->json([
            'entry' => AdminLogResource::make($auditLog->load('user:id,name')),
            /*
             * Everything else that happened to the same record, so an
             * administrator reading one entry can see the run of decisions
             * rather than a single line out of context.
             */
            'related' => $auditLog->subject_type === null ? [] : AdminLogResource::collection(
                AdminLog::query()
                    ->with('user:id,name')
                    ->where('subject_type', $auditLog->subject_type)
                    ->where('subject_id', $auditLog->subject_id)
                    ->whereKeyNot($auditLog->getKey())
                    ->recent()
                    ->limit(20)
                    ->get(),
            )->resolve(),
        ]);
    }

    /**
     * The administrators who have acted, for the filter dropdown.
     */
    public function actors(): JsonResponse
    {
        $actors = User::query()
            ->whereIn('id', AdminLog::query()->select('user_id')->whereNotNull('user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['actors' => $actors]);
    }
}
