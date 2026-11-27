<?php

declare(strict_types=1);

namespace AIArmada\Growth\Queries;

use AIArmada\Growth\Settings\GrowthSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

final readonly class ExperimentMiddlewareEnabled
{
    public function handle(): bool
    {
        $repository = config('settings.default_repository');
        $repositories = config('settings.repositories');

        if (is_string($repository) && $repository !== '' && is_array($repositories) && array_key_exists($repository, $repositories)) {
            try {
                return app(GrowthSettings::class)->experimentMiddlewareEnabled;
            } catch (MissingSettings) {
                return (bool) config('growth.features.experiment_middleware.enabled', false);
            }
        }

        return (bool) config('growth.features.experiment_middleware.enabled', false);
    }
}
