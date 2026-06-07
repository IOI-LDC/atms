<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;

class CompanySettingController extends Controller
{
    public function show()
    {
        $setting = CompanySetting::firstOrFail();

        return response()->json([
            'timezone' => $setting->timezone,
        ]);
    }

    public function update(Request $request)
    {
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
