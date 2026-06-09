<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Context;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerQuery;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerTuple\OwnerTupleColumns;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Enums\ResolveStrategy;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class ExperimentResolver
{
    public function resolve(
        string $id,
        ResolveStrategy $strategy = ResolveStrategy::Accessible,
        string $message = 'Growth experiment is not accessible in the current owner scope.',
    ): Experiment {
        $config = Experiment::ownerScopeConfig();

        if ($config->enabled) {
            /** @var Experiment $resolvedExperiment */
            $resolvedExperiment = OwnerWriteGuard::findOrFailForOwner(
                Experiment::class,
                $id,
                OwnerContext::CURRENT,
                $config->includeGlobal,
                $message,
            );

            $this->assertTrackedPropertyMatchesExperimentOwner($resolvedExperiment, $message);

            return $resolvedExperiment;
        }

        if (! TrackedProperty::ownerScopeConfig()->enabled) {
            $resolvedExperiment = Experiment::query()
                ->whereKey($id)
                ->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new InvalidArgumentException($message);
            }

            return $resolvedExperiment;
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal($owner, $message);

        $resolvedExperiment = Experiment::query()
            ->whereKey($id)
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

    public function resolveBySlug(
        string $slug,
        ResolveStrategy $strategy = ResolveStrategy::Accessible,
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

            $query = OwnerQuery::applyToEloquentBuilder(
                Experiment::query()->withoutGlobalScope(OwnerScope::class),
                $owner,
                $config->includeGlobal,
                $config->ownerTypeColumn,
                $config->ownerIdColumn,
            )
                ->where('slug', $normalizedSlug);

            $this->applyReadableFilter($query, $strategy);

            $resolvedExperiment = $query->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new AuthorizationException($message);
            }

            $this->assertTrackedPropertyMatchesExperimentOwner($resolvedExperiment, $message);

            return $resolvedExperiment;
        }

        if (! TrackedProperty::ownerScopeConfig()->enabled) {
            $query = Experiment::query()
                ->where('slug', $normalizedSlug);

            $this->applyReadableFilter($query, $strategy);

            $resolvedExperiment = $query->first();

            if (! $resolvedExperiment instanceof Experiment) {
                throw new InvalidArgumentException($message);
            }

            return $resolvedExperiment;
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal($owner, $message);

        $query = Experiment::query()
            ->where('slug', $normalizedSlug);

        $this->applyReadableFilter($query, $strategy);

        $resolvedExperiment = $query
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

    private function applyReadableFilter(Builder $query, ResolveStrategy $strategy): Builder
    {
        if ($strategy === ResolveStrategy::Readable) {
            $query->where('status', ExperimentStatus::Active->value);
        }

        return $query;
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
