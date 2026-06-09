<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Context;

use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;

/**
 * @phpstan-type ExperimentContextArray array{
 *     experiment_id: string,
 *     experiment_slug: string,
 *     variant_id: string,
 *     variant_code: string,
 *     assignment_id: string,
 *     module_type: string
 * }
 */
final readonly class ExperimentContext
{
    public string $slug;

    public string $variantCode;

    public string $assignmentId;

    public function __construct(
        public Experiment $experiment,
        public Variant $variant,
        public Assignment $assignment,
    ) {
        $this->slug = (string) $this->experiment->slug;
        $this->variantCode = (string) $this->variant->code;
        $this->assignmentId = (string) $this->assignment->getKey();
    }

    public function isVariant(string $code): bool
    {
        return $this->variantCode === $code;
    }

    public function isControl(): bool
    {
        return (bool) $this->variant->is_control;
    }

    public function variantCode(): string
    {
        return $this->variantCode;
    }

    public function assignmentId(): string
    {
        return $this->assignmentId;
    }

    public function experimentSlug(): string
    {
        return $this->slug;
    }

    /**
     * @return ExperimentContextArray
     */
    public function toArray(): array
    {
        return [
            'experiment_id' => (string) $this->experiment->getKey(),
            'experiment_slug' => $this->slug,
            'variant_id' => (string) $this->variant->getKey(),
            'variant_code' => $this->variantCode,
            'assignment_id' => $this->assignmentId,
            'module_type' => (string) $this->experiment->module_type,
        ];
    }
}
