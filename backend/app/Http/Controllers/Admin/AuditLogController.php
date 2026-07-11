<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $query = AuditLog::with('actor');

        // Partial match so free-text search works server-side (e.g. ?event=work_order
        // matches all work_order.* events). A full event name is a substring of itself,
        // so existing exact-match callers keep working.
        if ($request->filled('event')) {
            $query->where('event', 'like', "%{$request->string('event')}%");
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        // Date-window filters, matching the WorkOrderIndexQuery convention.
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        // per_page override: default 50, capped at 500 (audit rows carry wide JSON
        // blobs, so the cap is deliberately lower than the 5000 list cap).
        $perPage = min((int) $request->input('per_page', 50), 500);

        $logs = $query->latest('id')->cursorPaginate($perPage);

        return JsonResource::collection($logs)->toResponse($request);
    }
}
