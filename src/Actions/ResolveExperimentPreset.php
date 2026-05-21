<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Enums\ExperimentModuleType;

final class ResolveExperimentPreset
{
    /**
     * @return array{
     *     module_type: string,
     *     goal_event_name: string,
     *     goal_event_category: string,
     *     winner_metric: string,
     *     settings: array<string, mixed>
     * }
     */
    public function handle(string | ExperimentModuleType | null $moduleType = null): array
    {
        $resolvedModuleType = $moduleType instanceof ExperimentModuleType
            ? $moduleType
            : ExperimentModuleType::fromValue($moduleType);

        return [
            'module_type' => $resolvedModuleType->value,
            ...$resolvedModuleType->preset(),
        ];
    }
}
