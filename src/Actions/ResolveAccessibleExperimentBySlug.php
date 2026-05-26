<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerQuery;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleColumns;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ResolveAccessibleExperimentBySlug
{
    public function handle(
        string $slug,
        string $message = 'Growth experiment is not accessible in the current owner scope.',
    ): Experiment {
        $normalizedSlug = mb_trim($slug);

        if ($normalizedSlug === '') {
            throw new InvalidArgumentException('Growth experiment slug is required.');
        }

        $config = Experiment::ownerScopeConfig();

        if ($config->enabled) {
            $owner = OwnerContext::resolve();

            OwnerContext::assertResolvedOrExplicitGlobal($owner, $message);

            $resolvedExperiment = OwnerQuery::applyToEloquentBuilder(
                Experiment::query()->withoutGlobalScope(OwnerScope::class),
                $owner,
                $config->includeGlobal,
                $config->ownerTypeColumn,
                $config->ownerIdColumn,
            )
                ->where('slug', $normalizedSlug)
                ->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new AuthorizationException($message);
            }

            $this->assertTrackedPropertyMatchesExperimentOwner($resolvedExperiment, $message);

            return $resolvedExperiment;
        }

        if (! TrackedProperty::ownerScopeConfig()->enabled) {
            $resolvedExperiment = Experiment::query()
                ->where('slug', $normalizedSlug)
                ->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new AuthorizationException($message);
            }

            return $resolvedExperiment;
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal($owner, $message);

        $resolvedExperiment = Experiment::query()
            ->where('slug', $normalizedSlug)
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

        $trackedPropertyQuery = $trackedPropertyQuery->withoutGlobalScope(OwnerScope::class);

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
