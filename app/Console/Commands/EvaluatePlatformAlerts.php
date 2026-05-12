<?php

namespace App\Console\Commands;

use App\Services\PlatformAlertService;
use Illuminate\Console\Command;

class EvaluatePlatformAlerts extends Command
{
    protected $signature   = 'platform:evaluate-alerts';
    protected $description = 'Evaluate platform-level alert rules and dispatch notifications';

    public function handle(PlatformAlertService $service): int
    {
        $service->evaluateAll();
        $this->info('Platform alert rules evaluated.');
        return Command::SUCCESS;
    }
}
