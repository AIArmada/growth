<?php

declare(strict_types=1);

namespace AIArmada\Growth\EntryPoints\Commands;

use AIArmada\Growth\Actions\ArchiveExperiments;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ArchiveExperimentsCommand extends Command
{
    protected $signature = 'growth:archive-experiments
                          {--older-than=90 : Archive experiments inactive for N days}
                          {--dry-run : Dry run without persisting changes}';

    protected $description = 'Archive experiments that have been inactive beyond the threshold';

    public function handle(ArchiveExperiments $archive): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $olderThanDays = max(0, (int) $this->option('older-than'));
        $threshold = CarbonImmutable::now()->subDays($olderThanDays);

        $this->info($dryRun ? 'DRY RUN: Archiving experiments...' : 'Archiving experiments...');
        $this->line("Threshold: {$threshold->toDateString()}");

        $totalArchived = $archive->handle($threshold, $dryRun);

        $this->info("Experiments archived: {$totalArchived}");

        Log::info('Experiments archived', [
            'total' => $totalArchived,
            'older_than_days' => $olderThanDays,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
