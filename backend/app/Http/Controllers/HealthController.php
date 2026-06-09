<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function live()
    {
        return response()->json(['status' => 'alive']);
    }

    public function ready()
    {
        $db = false;
        $disk = false;

        try {
            DB::connection()->getPdo();
            $db = true;
        } catch (\Throwable $e) {
            Log::error('Health check: database unreachable', ['error' => $e->getMessage()]);
        }

        try {
            $diskName = config('atms.attachment_disk', 'attachments');
            $disk = Storage::disk($diskName)->put('.health-check', 'ok') && Storage::disk($diskName)->delete('.health-check');
        } catch (\Throwable $e) {
            Log::error('Health check: attachment disk unreachable', ['error' => $e->getMessage()]);
        }

        $healthy = $db && $disk;

        return response()->json([
            'status' => $healthy ? 'ready' : 'degraded',
            'database' => $db ? 'ok' : 'unreachable',
            'attachments' => $disk ? 'ok' : 'unreachable',
        ], $healthy ? 200 : 503);
    }
}
