<?php

namespace App\Http\Controllers;

use App\Models\Platform\PlatformAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AnnouncementDismissController extends Controller
{
    public function dismiss(PlatformAnnouncement $announcement): RedirectResponse
    {
        DB::table('platform_announcement_dismissals')->insertOrIgnore([
            'announcement_id' => $announcement->id,
            'user_id'         => auth()->id(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return back();
    }
}
