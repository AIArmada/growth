<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Assignment;
use Carbon\CarbonImmutable;
use Throwable;

final class RepairExperimentAssignment
{
    public const string UNCHANGED = 'unchanged';

    public const string CHANGED = 'changed';

    public const string QUARANTINED = 'quarantined';

    public function __construct(
        private readonly ResolveExperimentAssignment $resolver,
    ) {}

    public function handle(Assignment $assignment, bool $persist = true): string
    {
        try {
            $experiment = $assignment->experiment;

            if ($experiment === null || $experiment->status !== ExperimentStatus::Active) {
                throw new \RuntimeException('The parent experiment is missing or inactive.');
            }

            $subjectKey = mb_trim((string) $assignment->subject_key);

            if ($subjectKey === '') {
                throw new \RuntimeException('The assignment subject key is empty.');
            }

            [$variant, $bucket] = $this->resolver->variantForSubject($experiment, $subjectKey);

            if ((string) $assignment->variant_id === (string) $variant->getKey() && $assignment->bucket === $bucket) {
                return self::UNCHANGED;
            }

            if ($persist) {
                Assignment::withoutEvents(function () use ($assignment, $variant, $bucket): void {
                    $metadata = is_array($assignment->metadata) ? $assignment->metadata : [];
                    unset($metadata['repair_quarantine']);
                    $assignment->variant_id = (string) $variant->getKey();
                    $assignment->bucket = $bucket;
                    $assignment->metadata = $metadata;
                    $assignment->saveQuietly();
                });
            }

            return self::CHANGED;
        } catch (Throwable $exception) {
            if ($persist) {
                Assignment::withoutEvents(function () use ($assignment, $exception): void {
                    $metadata = is_array($assignment->metadata) ? $assignment->metadata : [];
                    $metadata['repair_quarantine'] = [
                        'reason' => $exception->getMessage(),
                        'recorded_at' => CarbonImmutable::now()->toIso8601String(),
                    ];
                    $assignment->metadata = $metadata;
                    $assignment->saveQuietly();
                });
            }

            return self::QUARANTINED;
        }
    }
}
