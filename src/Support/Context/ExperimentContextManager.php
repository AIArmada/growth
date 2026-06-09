<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Context;

use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class ExperimentContextManager
{
    public const string EXPERIMENT_ATTRIBUTE = 'growth.experiment';

    public const string VARIANT_ATTRIBUTE = 'growth.variant';

    public const string ASSIGNMENT_ATTRIBUTE = 'growth.assignment';

    public function current(): ?ExperimentContext
    {
        $request = $this->request();

        if (! $request instanceof Request) {
            return null;
        }

        $experiment = $request->attributes->get(self::EXPERIMENT_ATTRIBUTE);
        $variant = $request->attributes->get(self::VARIANT_ATTRIBUTE);
        $assignment = $request->attributes->get(self::ASSIGNMENT_ATTRIBUTE);

        if (! $experiment instanceof Experiment || ! $variant instanceof Variant || ! $assignment instanceof Assignment) {
            return null;
        }

        return new ExperimentContext($experiment, $variant, $assignment);
    }

    public function store(Request $request, Experiment $experiment, Assignment $assignment): void
    {
        $assignment->setRelation('experiment', $experiment);
        $assignment->loadMissing('variant');

        $variant = $assignment->variant;

        if (! $variant instanceof Variant) {
            throw new RuntimeException('Experiment assignment variant could not be resolved for request context storage.');
        }

        $variant->setRelation('experiment', $experiment);
        $assignment->setRelation('variant', $variant);

        $request->attributes->set(self::EXPERIMENT_ATTRIBUTE, $experiment);
        $request->attributes->set(self::VARIANT_ATTRIBUTE, $variant);
        $request->attributes->set(self::ASSIGNMENT_ATTRIBUTE, $assignment);
    }

    public function hasCurrent(): bool
    {
        return $this->current() instanceof ExperimentContext;
    }

    public function experiment(): ?Experiment
    {
        return $this->current()?->experiment;
    }

    public function variant(): ?Variant
    {
        return $this->current()?->variant;
    }

    public function assignment(): ?Assignment
    {
        return $this->current()?->assignment;
    }

    public function isVariant(string $code): bool
    {
        return $this->current()?->isVariant($code) ?? false;
    }

    public function isControl(): bool
    {
        return $this->current()?->isControl() ?? false;
    }

    public function variantCode(): ?string
    {
        return $this->current()?->variantCode();
    }

    public function experimentSlug(): ?string
    {
        return $this->current()?->experimentSlug();
    }

    public function assignmentId(): ?string
    {
        return $this->current()?->assignmentId();
    }

    /**
     * @return array<string, string>|null
     */
    public function toArray(): ?array
    {
        return $this->current()?->toArray();
    }

    private function request(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }
}
