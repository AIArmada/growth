<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Support\ExperimentAssignmentResolver;
use AIArmada\Growth\Support\ExperimentContextMerger;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ProjectExperimentContextIntoSignalProperties
{
    public function __construct(
        private readonly BuildExperimentSignalProperties $buildExperimentSignalProperties,
        private readonly ExperimentAssignmentResolver $assignmentResolver,
        private readonly ExperimentContextMerger $contextMerger,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function handle(Model $source, TrackedProperty $trackedProperty, array $properties = []): array
    {
        if (! config('growth.integrations.signals.enabled', true)) {
            return $properties;
        }

        $trackedProperty = $this->resolveTrackedPropertyForCurrentScope($trackedProperty);

        $assignments = $this->assignmentResolver->resolve($source, $trackedProperty);

        if ($assignments->isEmpty()) {
            return $this->contextMerger->merge($source, $properties);
        }

        $contexts = collect($this->buildExperimentSignalProperties->contextsForAssignments($assignments))
            ->unique('experiment_id')
            ->values()
            ->all();

        if ($contexts === []) {
            return $this->contextMerger->merge($source, $properties);
        }

        /** @var array<string, string> $primaryContext */
        $primaryContext = $contexts[0];

        $enrichedContext = array_merge($primaryContext, [
            'experiment_contexts' => $contexts,
        ]);

        return $this->contextMerger->merge($source, array_merge($properties, $enrichedContext));
    }

    private function resolveTrackedPropertyForCurrentScope(TrackedProperty $trackedProperty): TrackedProperty
    {
        if (Experiment::ownerScopeConfig()->enabled || TrackedProperty::ownerScopeConfig()->enabled) {
            $owner = OwnerContext::resolve();

            OwnerContext::assertResolvedOrExplicitGlobal(
                $owner,
                'Tracked property is not accessible in the current owner scope.',
            );

            $resolvedTrackedProperty = app(ScopeSignalQueryToOwner::class)
                ->handle(
                    TrackedProperty::query(),
                    $owner,
                    TrackedProperty::ownerScopeConfig()->includeGlobal,
                )
                ->whereKey((string) $trackedProperty->getKey())
                ->first();

            if ($resolvedTrackedProperty instanceof TrackedProperty) {
                return $resolvedTrackedProperty;
            }

            throw new AuthorizationException('Tracked property is not accessible in the current owner scope.');
        }

        $resolvedTrackedProperty = TrackedProperty::query()
            ->whereKey((string) $trackedProperty->getKey())
            ->first();

        if (! $resolvedTrackedProperty instanceof TrackedProperty) {
            throw new InvalidArgumentException('Tracked property could not be resolved for signal enrichment.');
        }

        return $resolvedTrackedProperty;
    }
}
