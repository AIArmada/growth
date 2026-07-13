<?php

declare(strict_types=1);

namespace AIArmada\Growth\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\Growth\Actions\RepairExperimentAssignment;
use AIArmada\Growth\Models\Assignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecomputeExperimentAssignmentsCommand extends Command
{
    protected $signature = 'growth:recompute-assignments
                          {--dry-run : Report changes without persisting them}';

    protected $description = 'Repair experiment assignments with the canonical deterministic allocator';

    public function __construct(
        private readonly RepairExperimentAssignment $repair,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $runner = new OwnerBatchRunner(Assignment::class, ['enabled' => 'commerce-support.owner.enabled']);

        $results = $runner->forEach(function () use ($dryRun): array {
            $counts = ['changed' => 0, 'quarantined' => 0, 'unchanged' => 0];

            Assignment::query()
                ->with(['experiment.variants', 'variant'])
                ->orderBy('id')
                ->each(function (Assignment $assignment) use (&$counts, $dryRun): void {
                    $result = $this->repair->handle($assignment, ! $dryRun);
                    $counts[$result]++;
                });

            return $counts;
        });

        $changed = (int) collect($results)->sum('changed');
        $quarantined = (int) collect($results)->sum('quarantined');
        $unchanged = (int) collect($results)->sum('unchanged');
        $prefix = $dryRun ? 'Would change' : 'Changed';

        $this->info("{$prefix}: {$changed}; " . ($dryRun ? 'would quarantine' : 'quarantined') . ": {$quarantined}; unchanged: {$unchanged}");

        Log::info('Experiment assignment repair completed', compact('changed', 'quarantined', 'unchanged', 'dryRun'));

        return self::SUCCESS;
    }
}
