<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support;

final readonly class ExperimentMetricBatchRow
{
    public function __construct(
        public string $recordType,
        public string $recordId,
        public ?string $experimentId,
        public ?string $trackedPropertyId,
        public ?string $variantId,
        public ?string $subjectKey,
        public mixed $assignedAt,
        public mixed $occurredAt,
        public ?string $eventName,
        public ?string $eventCategory,
        public ?int $revenueMinor,
        public ?string $currency,
        public mixed $properties,
        public ?string $code,
        public ?string $name,
        public ?int $position,
    ) {}
}
