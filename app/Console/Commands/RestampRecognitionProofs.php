<?php

namespace App\Console\Commands;

use App\Services\ProductPromotionService;
use Illuminate\Console\Command;

class RestampRecognitionProofs extends Command
{
    protected $signature = 'promotion:restamp-proofs
        {--write : Re-stamp the proofs. Without it nothing is changed and the count is reported}';

    protected $description = 'APP_KEY rotation: re-stamp recognition proofs made under a previous key (see docs/runbooks/app-key-rotation-proposal.md).';

    public function handle(ProductPromotionService $service): int
    {
        $previous = array_values(array_filter((array) config('app.previous_keys')));

        if ($previous === []) {
            // With no previous key a proof made under one cannot be told from an
            // invalid one, so "nothing left to migrate" could not be honest.
            $this->error('No previous key is configured (APP_PREVIOUS_KEYS): there is nothing to compare against.');

            return 2;
        }

        $write = (bool) $this->option('write');
        $counts = $service->restampProofs($previous, $write);

        $this->line("{$counts['current']} already under the current key");
        $this->line($write ? "{$counts['restamped']} re-stamped" : "{$counts['restamped']} would be re-stamped");
        $this->line("{$counts['invalid']} already invalid (owner changed): left as they are");

        // Without --write this is the check to run before a previous key is
        // removed: non-zero while any proof still depends on it.
        return ! $write && $counts['restamped'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
