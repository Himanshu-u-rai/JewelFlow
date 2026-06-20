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

    /** Shared validation rules for create/update. */
    private function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string', 'max:5000'],
            'cta_label'    => ['nullable', 'string', 'max:80'],
            'cta_url'      => ['nullable', 'string', 'max:2048', 'url'],
            // info/warning/critical = system notice; banner = big offers/deals;
            // cross_promo = overrides the product cross-promo toast.
            'type'         => ['required', 'in:info,warning,critical,banner,cross_promo'],
            'target'       => ['required', 'in:all,plan,edition'],
            'target_value' => ['nullable', 'string', 'max:100'],
            'realm'        => ['nullable', 'in:erp,dhiran'],
            'publish_at'   => ['nullable', 'date'],
            'expires_at'   => ['nullable', 'date', 'after:publish_at'],
            'send_email'   => ['boolean'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

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

        return back()->with('success', 'Message created.');
    }

    public function update(PlatformAnnouncement $announcement, Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $before = $announcement->toArray();

        $announcement->update([
            ...$validated,
            'send_email' => $request->boolean('send_email'),
        ]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'announcement.update',
            PlatformAnnouncement::class,
            $announcement->id,
            $before,
            $announcement->fresh()->toArray(),
            null,
            $request
        );

        return back()->with('success', 'Message updated.');
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
