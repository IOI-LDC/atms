<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanySettingController extends Controller
{
    public function show()
    {
        Gate::authorize('manage', CompanySetting::class);

        $setting = CompanySetting::firstOrFail();

        return response()->json([
            'timezone' => $setting->timezone,
        ]);
    }

    public function update(Request $request)
    {
        Gate::authorize('manage', CompanySetting::class);

        $data = $request->validate([
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $setting = CompanySetting::firstOrFail();
        $setting->update($data);

        return response()->json([
            'timezone' => $setting->timezone,
        ]);
    }
}
