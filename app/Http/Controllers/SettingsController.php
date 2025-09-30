<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function getScript(Request $request)
    {
        $setting = Setting::where('key', 'script')->first();
        $last = Cache::get('settings_script_last_change');
        if (!$last) {
            $last = optional($setting?->updated_at)->timestamp ?? 0;
            Cache::forever('settings_script_last_change', $last);
        }
        $e = 'W/"script:'.$last.'"';
        if ($request->headers->get('If-None-Match') === $e) {
            return response('', 304, ['ETag' => $e]);
        }
        $value = $setting?->value ?? '';
        return response()->json(['script' => $value])->withHeaders(['ETag' => $e]);
    }

    public function saveScript(Request $request)
    {
        $data = $request->validate(['script' => 'nullable|string']);
        $s = Setting::updateOrCreate(['key' => 'script'], ['value' => $data['script'] ?? '']);
        Cache::forever('settings_script_last_change', now()->timestamp);
        return response()->json(['ok' => true, 'script' => $s->value]);
    }
}
