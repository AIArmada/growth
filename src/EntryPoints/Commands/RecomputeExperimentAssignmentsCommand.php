<?php

declare(strict_types=1);

namespace AIArmada\Growth\EntryPoints\Commands;

use AIArmada\Growth\Actions\RecomputeExperimentAssignments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecomputeExperimentAssignmentsCommand extends Command
{
    protected $signature = 'growth:recompute-assignments
                          {--dry-run : Report changes without persisting them}';

    protected $description = 'Repair experiment assignments with the canonical deterministic allocator';

    public function handle(RecomputeExperimentAssignments $recompute): int
    {
        $dryRun = (bool) $this->option('dry-run');
        ['changed' => $changed, 'quarantined' => $quarantined, 'unchanged' => $unchanged] = $recompute->handle($dryRun);
        $prefix = $dryRun ? 'Would change' : 'Changed';

        $this->info("{$prefix}: {$changed}; " . ($dryRun ? 'would quarantine' : 'quarantined') . ": {$quarantined}; unchanged: {$unchanged}");

        Log::info('Experiment assignment repair completed', compact('changed', 'quarantined', 'unchanged', 'dryRun'));

        return self::SUCCESS;
    }
}
