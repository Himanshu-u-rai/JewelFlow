<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Platform\PlatformAdmin;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SystemJobController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(Request $request): View
    {
        $jobs = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($job) => $this->mapJobRecord($job));

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($job) => $this->mapFailedJob($job));

        $admin = auth('platform_admin')->user();
        if ($admin) {
            $this->audit->log(
                $admin,
                'admin.jobs_viewed',
                PlatformAdmin::class,
                $admin->id,
                null,
                ['queued' => $jobs->count(), 'failed' => $failedJobs->count()],
                null,
                $request
            );
        }

        return view('super-admin.system.jobs.index', [
            'jobs' => $jobs,
            'failedJobs' => $failedJobs,
        ]);
    }

    public function show(int $id, Request $request): View
    {
        $job = DB::table('failed_jobs')->where('id', $id)->first();
        if (!$job) {
            abort(404);
        }

        $mapped = $this->mapFailedJob($job);

        $admin = auth('platform_admin')->user();
        if ($admin) {
            $this->audit->log(
                $admin,
                'admin.job_log_viewed',
                PlatformAdmin::class,
                $admin->id,
                null,
                ['job_id' => $id, 'job' => $mapped['name']],
                null,
                $request
            );
        }

        return view('super-admin.system.jobs.show', [
            'job' => $mapped,
        ]);
    }

    public function retry(int $id, Request $request): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        $admin = auth('platform_admin')->user();
        if ($admin) {
            $this->audit->log(
                $admin,
                'admin.job_retried',
                PlatformAdmin::class,
                $admin->id,
                null,
                ['job_id' => $id],
                null,
                $request
            );
        }

        return back()->with('success', 'Job retry dispatched.');
    }

    private function mapJobRecord(object $job): array
    {
        $payload = json_decode($job->payload, true) ?: [];
        $name = $payload['displayName'] ?? $payload['job'] ?? 'Queued Job';
        $shop = $this->resolveShopFromPayload($payload);

        return [
            'id' => $job->id,
            'queue' => $job->queue,
            'attempts' => $job->attempts,
            'reserved_at' => $job->reserved_at,
            'available_at' => $job->available_at,
            'created_at' => $job->created_at,
            'name' => $name,
            'shop' => $shop,
        ];
    }

    private function mapFailedJob(object $job): array
    {
        $payload = json_decode($job->payload, true) ?: [];
        $name = $payload['displayName'] ?? $payload['job'] ?? 'Failed Job';
        $shop = $this->resolveShopFromPayload($payload);

        return [
            'id' => $job->id,
            'queue' => $job->queue,
            'failed_at' => $job->failed_at,
            'name' => $name,
            'exception' => $job->exception,
            'payload' => $payload,
            'shop' => $shop,
        ];
    }

    private function resolveShopFromPayload(array $payload): ?array
    {
        $command = $payload['data']['command'] ?? null;
        if (!is_string($command)) {
            return null;
        }

        $shopId = $this->extractShopId($command);
        if (!$shopId) {
            $importId = $this->extractImportId($command);
            if ($importId) {
                $shopId = Import::withoutTenant()->where('id', $importId)->value('shop_id');
            }
        }

        if (!$shopId) {
            return null;
        }

        $shop = Shop::query()->select('id', 'name')->find($shopId);
        if (!$shop) {
            return null;
        }

        return [
            'id' => $shop->id,
            'name' => $shop->name,
        ];
    }

    private function extractShopId(string $command): ?int
    {
        if (preg_match('/shop_id";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/shopId";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/shop_id";s:\\d+:"(\d+)"/', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractImportId(string $command): ?int
    {
        if (preg_match('/importId";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/import_id";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
