<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ErpSyncController extends Controller
{
    public function syncAssets(): JsonResponse
    {
        // Only Admin or Maintenance Manager
        Gate::authorize('manage', \App\Models\Asset::class);

        SyncErpAssetsJob::dispatch(auth()->id());

        return response()->json(['message' => 'ERP Asset synchronization started.']);
    }

    public function syncParts(): JsonResponse
    {
        Gate::authorize('manage', \App\Models\Part::class);

        SyncErpPartsJob::dispatch(auth()->id());

        return response()->json(['message' => 'ERP Part synchronization started.']);
    }
}
