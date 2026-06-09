<?php

declare(strict_types=1);

use AIArmada\Growth\Support\Context\ExperimentContext;
use AIArmada\Growth\Support\Context\ExperimentContextManager;

if (! function_exists('experiment')) {
    function experiment(): ?ExperimentContext
    {
        /** @var ExperimentContextManager $manager */
        $manager = app(ExperimentContextManager::class);

        return $manager->current();
    }
}
