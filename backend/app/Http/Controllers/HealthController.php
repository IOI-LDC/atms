<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
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
        } catch (\Throwable) {
        }

        try {
            $disk = Storage::disk(config('atms.attachment_disk', 'attachments'))->exists('.');
        } catch (\Throwable) {
        }

        return response()->json([
            'status' => ($db && $disk) ? 'ready' : 'degraded',
            'database' => $db ? 'ok' : 'unreachable',
            'attachments' => $disk ? 'ok' : 'unreachable',
        ]);
    }
}
