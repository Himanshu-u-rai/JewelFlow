<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformSetting;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        $settings = [
            'retailer_enabled'     => PlatformSetting::retailerEnabled(),
            'manufacturer_enabled' => PlatformSetting::manufacturerEnabled(),
            'dhiran_enabled'       => PlatformSetting::dhiranEnabled(),
        ];

        return view('super-admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $retailer     = $request->boolean('retailer_enabled');
        $manufacturer = $request->boolean('manufacturer_enabled');
        $dhiran       = $request->boolean('dhiran_enabled');

        if (!$retailer && !$manufacturer && !$dhiran) {
            return back()->with('error', 'At least one edition must remain enabled. You cannot disable all.');
        }

        $before = [
            'retailer_enabled'     => PlatformSetting::retailerEnabled(),
            'manufacturer_enabled' => PlatformSetting::manufacturerEnabled(),
            'dhiran_enabled'       => PlatformSetting::dhiranEnabled(),
        ];

        PlatformSetting::set('retailer_enabled',     $retailer     ? 'true' : 'false');
        PlatformSetting::set('manufacturer_enabled', $manufacturer ? 'true' : 'false');
        PlatformSetting::set('dhiran_enabled',       $dhiran       ? 'true' : 'false');

        $after = [
            'retailer_enabled'     => $retailer,
            'manufacturer_enabled' => $manufacturer,
            'dhiran_enabled'       => $dhiran,
        ];

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.platform_settings.updated',
            PlatformSetting::class,
            null,
            $before,
            $after,
            'Platform shop-type availability updated',
            $request
        );

        return back()->with('success', 'Platform settings saved.');
    }
}
