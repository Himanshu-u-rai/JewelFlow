<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAnnouncement;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(): View
    {
        $announcements = PlatformAnnouncement::orderByDesc('created_at')->paginate(20);

        return view('super-admin.announcements.index', compact('announcements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string', 'max:5000'],
            'type'         => ['required', 'in:info,warning,critical'],
            'target'       => ['required', 'in:all,plan,edition'],
            'target_value' => ['nullable', 'string', 'max:100'],
            'publish_at'   => ['nullable', 'date'],
            'expires_at'   => ['nullable', 'date', 'after:publish_at'],
            'send_email'   => ['boolean'],
        ]);

        $announcement = PlatformAnnouncement::create([
            ...$validated,
            'send_email'           => $request->boolean('send_email'),
            'created_by_admin_id'  => auth('platform_admin')->id(),
        ]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'announcement.create',
            PlatformAnnouncement::class,
            $announcement->id,
            null,
            $announcement->toArray(),
            null,
            $request
        );

        return back()->with('success', 'Announcement created.');
    }

    public function destroy(PlatformAnnouncement $announcement, Request $request): RedirectResponse
    {
        $this->audit->log(
            auth('platform_admin')->user(),
            'announcement.delete',
            PlatformAnnouncement::class,
            $announcement->id,
            $announcement->toArray(),
            null,
            null,
            $request
        );

        $announcement->delete();

        return back()->with('success', 'Announcement deleted.');
    }
}
