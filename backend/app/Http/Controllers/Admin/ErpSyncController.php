<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncErpPartsJob;
use App\Models\Asset;
use App\Models\ErpSyncJob;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ErpSyncController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class); // Reusing Asset policy for view access

        $jobs = ErpSyncJob::latest()->get();

        return response()->json(['data' => $jobs]);
    }

    public function syncParts(): JsonResponse
    {
        Gate::authorize('manage', Part::class);

        SyncErpPartsJob::dispatch(auth()->id());

        return response()->json(['message' => 'ERP Part synchronization started.']);
    }
}
