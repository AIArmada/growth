<?php

declare(strict_types=1);

namespace AIArmada\Growth\Livewire\Concerns;

use AIArmada\Growth\Support\Context\ExperimentContext;

/** @phpstan-ignore trait.unused */
trait InteractsWithExperimentContext
{
    public function experimentContext(): ?ExperimentContext
    {
        return experiment();
    }

    public function experimentVariantCode(): ?string
    {
        return $this->experimentContext()?->variantCode();
    }

    public function experimentSlug(): ?string
    {
        return $this->experimentContext()?->experimentSlug();
    }

    public function isExperimentVariant(string $code): bool
    {
        return $this->experimentContext()?->isVariant($code) ?? false;
    }

    public function isExperimentControl(): bool
    {
        return $this->experimentContext()?->isControl() ?? false;
    }
}
