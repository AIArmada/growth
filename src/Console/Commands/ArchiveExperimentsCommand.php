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
        $olderThanDays = (int) $this->option('older-than');
        $threshold = CarbonImmutable::now()->subDays($olderThanDays);

        $this->info($dryRun ? 'DRY RUN: Archiving experiments...' : 'Archiving experiments...');
        $this->line("Threshold: {$threshold->toDateString()}");

        $runner = new OwnerBatchRunner(Experiment::class, [
            'enabled' => 'commerce-support.owner.enabled',
        ]);

        $total = $runner->forEach(function () use ($threshold, $dryRun): array {
            $experiments = Experiment::query()
                ->whereIn('status', [
                    ExperimentStatus::Completed->value,
                    ExperimentStatus::Cancelled->value,
                ])
                ->where('updated_at', '<', $threshold)
                ->get();

            $archived = 0;

            foreach ($experiments as $experiment) {
                if (! $dryRun) {
                    $experiment->update(['status' => ExperimentStatus::Archived]);
                }

                $archived++;
            }

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
