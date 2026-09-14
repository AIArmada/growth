<?php

declare(strict_types=1);

namespace AIArmada\Growth\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerBatchRunner;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Experiment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ArchiveExperimentsCommand extends Command
{
    protected $signature = 'growth:archive-experiments
                          {--older-than=90 : Archive experiments inactive for N days}
                          {--dry-run : Dry run without persisting changes}';

    protected $description = 'Archive experiments that have been inactive beyond the threshold';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $olderThanDays = max(0, (int) $this->option('older-than'));
        $threshold = CarbonImmutable::now()->subDays($olderThanDays);

        $this->info($dryRun ? 'DRY RUN: Archiving experiments...' : 'Archiving experiments...');
        $this->line("Threshold: {$threshold->toDateString()}");

        $runner = new OwnerBatchRunner(Experiment::class, [
            'enabled' => 'growth.features.owner.enabled',
        ]);

        $total = $runner->forEach(function () use ($threshold, $dryRun): array {
            $archived = 0;

            Experiment::query()
                ->whereIn('status', [
                    ExperimentStatus::Concluded->value,
                ])
                ->where('updated_at', '<', $threshold)
                ->chunkById(500, function ($experiments) use (&$archived, $dryRun): void {
                    foreach ($experiments as $experiment) {
                        if (! $dryRun) {
                            $experiment->transitionTo(ExperimentStatus::Archived)->save();
                        }

                        $archived++;
                    }
                });

            return ['archived' => $archived];
        });

        $totalArchived = collect($total)->sum('archived');

        $this->info("Experiments archived: {$totalArchived}");

        Log::info('Experiments archived', [
            'total' => $totalArchived,
            'older_than_days' => $olderThanDays,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
