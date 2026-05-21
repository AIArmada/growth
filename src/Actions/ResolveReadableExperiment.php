<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleColumns;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ResolveReadableExperiment
{
    public function handle(
        Experiment | string $experiment,
        string $message = 'Growth experiment is not accessible in the current owner scope.',
    ): Experiment {
        $experimentId = $experiment instanceof Experiment
            ? (string) $experiment->getKey()
            : (string) $experiment;

        $config = Experiment::ownerScopeConfig();

        if ($config->enabled) {
            /** @var Experiment $resolvedExperiment */
            $resolvedExperiment = OwnerWriteGuard::findOrFailForOwner(
                Experiment::class,
                $experimentId,
                OwnerContext::CURRENT,
                $config->includeGlobal,
                $message,
            );

            $this->assertTrackedPropertyMatchesExperimentOwner($resolvedExperiment, $message);

            return $resolvedExperiment;
        }

        if (! TrackedProperty::ownerScopeConfig()->enabled) {
            $resolvedExperiment = Experiment::query()
                ->whereKey($experimentId)
                ->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new InvalidArgumentException($message);
            }

            return $resolvedExperiment;
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal($owner, $message);

        $resolvedExperiment = Experiment::query()
            ->whereKey($experimentId)
            ->whereIn(
                'tracked_property_id',
                app(ScopeSignalQueryToOwner::class)
                    ->handle(
                        TrackedProperty::query(),
                        $owner,
                        TrackedProperty::ownerScopeConfig()->includeGlobal,
                    )
                    ->select('id'),
            )
            ->first();

        if (! $resolvedExperiment instanceof Experiment) {
            throw new AuthorizationException($message);
        }

        return $resolvedExperiment;
    }

    private function assertTrackedPropertyMatchesExperimentOwner(Experiment $experiment, string $message): void
    {
        $ownerColumns = OwnerTupleColumns::forModelClass(TrackedProperty::class);

        /** @var Builder<TrackedProperty> $trackedPropertyQuery */
        $trackedPropertyQuery = TrackedProperty::query();

        if (method_exists(TrackedProperty::class, 'scopeWithoutOwnerScope')) {
            /** @phpstan-ignore-next-line dynamic Eloquent scope */
            $trackedPropertyQuery = $trackedPropertyQuery->withoutOwnerScope();
        }

        $trackedPropertyQuery->whereKey((string) $experiment->tracked_property_id);

        if (($experiment->owner_type === null) !== ($experiment->owner_id === null)) {
            throw new AuthorizationException($message);
        }

        if ($experiment->owner_type === null && $experiment->owner_id === null) {
            $trackedPropertyQuery
                ->whereNull($ownerColumns->ownerTypeColumn)
                ->whereNull($ownerColumns->ownerIdColumn);
        } else {
            $trackedPropertyQuery
                ->where($ownerColumns->ownerTypeColumn, $experiment->owner_type)
                ->where($ownerColumns->ownerIdColumn, $experiment->owner_id);
        }

        if (! $trackedPropertyQuery->exists()) {
            throw new AuthorizationException($message);
        }
    }
}
