<?php

declare(strict_types=1);

namespace AIArmada\Growth\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\Growth\Models\Assignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecomputeExperimentAssignmentsCommand extends Command
{
    protected $signature = 'growth:recompute-assignments
                          {--dry-run : Dry run without persisting changes}';

    protected $description = 'Recompute experiment assignments per owner';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'DRY RUN: Recomputing experiment assignments...' : 'Recomputing experiment assignments...');

        $runner = new OwnerBatchRunner(Assignment::class, [
            'enabled' => 'commerce-support.owner.enabled',
        ]);

        $total = $runner->forEach(function () use ($dryRun): array {
            $assignments = Assignment::query()
                ->whereNull('variant_id')
                ->orWhere('variant_id', '')
                ->get();

            $recomputed = 0;

            foreach ($assignments as $assignment) {
                if (! $dryRun) {
                    $experiment = $assignment->experiment;
                    if ($experiment !== null && $experiment->variants()->exists()) {
                        $variant = $experiment->variants()->inRandomOrder()->first();
                        if ($variant !== null) {
                            $assignment->update(['variant_id' => $variant->id]);
                        }
                    }
                }

                $recomputed++;
            }

            return ['recomputed' => $recomputed];
        });

        $totalRecomputed = collect($total)->sum('recomputed');

        $this->info("Total assignments processed: {$totalRecomputed}");

        Log::info('Experiment assignments recomputed', [
            'total' => $totalRecomputed,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
