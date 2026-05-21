<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ProjectExperimentContextIntoSignalProperties
{
    public function __construct(
        private readonly BuildExperimentSignalProperties $buildExperimentSignalProperties,
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

        $assignments = $this->resolveAssignments($source, $trackedProperty);

        if ($assignments->isEmpty()) {
            return $properties;
        }

        $contexts = collect($this->buildExperimentSignalProperties->contextsForAssignments($assignments))
            ->unique('experiment_id')
            ->values()
            ->all();

        if ($contexts === []) {
            return $properties;
        }

        /** @var array<string, string> $primaryContext */
        $primaryContext = $contexts[0];

        $enrichedContext = array_filter(
            array_merge($primaryContext, [
                'experiment_contexts' => $contexts,
            ]),
            static fn (mixed $value): bool => $value !== null,
        );

        return array_merge($properties, $enrichedContext);
    }

    /**
     * @return Collection<int, Assignment>
     */
    private function resolveAssignments(Model $source, TrackedProperty $trackedProperty): Collection
    {
        $identityIds = $this->resolveIdentityIds($source, $trackedProperty);
        $subjectKeys = $this->candidateSubjectKeys($source);

        if ($identityIds === [] && $subjectKeys === []) {
            return new Collection;
        }

        $assignmentsQuery = Assignment::query()->with(['experiment', 'variant']);
        $experimentsQuery = Experiment::query()
            ->where('tracked_property_id', $trackedProperty->getKey())
            ->select('id');

        $assignmentsQuery = $this->scopeQueryToTrackedPropertyOwner($assignmentsQuery, $trackedProperty);
        $experimentsQuery = $this->scopeQueryToTrackedPropertyOwner($experimentsQuery, $trackedProperty);

        return $assignmentsQuery
            ->whereIn(
                'experiment_id',
                $experimentsQuery,
            )
            ->where(function (Builder $query) use ($identityIds, $subjectKeys): void {
                if ($identityIds !== []) {
                    $query->whereIn('signal_identity_id', $identityIds);
                }

                if ($subjectKeys === []) {
                    return;
                }

                if ($identityIds !== []) {
                    $query->orWhereIn('subject_key', $subjectKeys);

                    return;
                }

                $query->whereIn('subject_key', $subjectKeys);
            })
            ->orderByDesc('last_seen_at')
            ->orderByDesc('assigned_at')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function resolveIdentityIds(Model $source, TrackedProperty $trackedProperty): array
    {
        $externalIds = $this->candidateExternalIds($source);
        $anonymousIds = $this->candidateAnonymousIds($source);

        if ($externalIds === [] && $anonymousIds === []) {
            return [];
        }

        $identityQuery = $this->scopeQueryToTrackedPropertyOwner(
            SignalIdentity::query()->where('tracked_property_id', $trackedProperty->getKey()),
            $trackedProperty,
        );

        return $identityQuery
            ->where(function (Builder $query) use ($anonymousIds, $externalIds): void {
                if ($externalIds !== []) {
                    $query->whereIn('external_id', $externalIds);
                }

                if ($anonymousIds === []) {
                    return;
                }

                if ($externalIds !== []) {
                    $query->orWhereIn('anonymous_id', $anonymousIds);

                    return;
                }

                $query->whereIn('anonymous_id', $anonymousIds);
            })
            ->pluck('id')
            ->filter(static fn (mixed $value): bool => is_scalar($value))
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function candidateExternalIds(Model $source): array
    {
        return $this->uniqueStrings([
            $this->stringValue($this->attributeValue($source, 'customer_id')),
        ]);
    }

    /**
     * @return list<string>
     */
    private function candidateAnonymousIds(Model $source): array
    {
        $metadata = $this->attributeValue($source, 'metadata');

        return $this->uniqueStrings([
            $this->stringValue($this->attributeValue($source, 'cart_id')),
            is_array($metadata) ? $this->stringValue(data_get($metadata, 'cart_id')) : null,
        ]);
    }

    private function attributeValue(Model $source, string $attribute): mixed
    {
        if (! array_key_exists($attribute, $source->getAttributes())) {
            return null;
        }

        return $source->getAttribute($attribute);
    }

    /**
     * @return list<string>
     */
    private function candidateSubjectKeys(Model $source): array
    {
        return array_map(
            static fn (string $anonymousId): string => 'anonymous:' . $anonymousId,
            $this->candidateAnonymousIds($source),
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '')));
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

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeQueryToTrackedPropertyOwner(Builder $query, TrackedProperty $trackedProperty): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $query->getModel()::class;

        if (! method_exists($modelClass, 'ownerScopeConfig')) {
            return $query;
        }

        $config = $modelClass::ownerScopeConfig();

        if (! $config->enabled && ! Experiment::ownerScopeConfig()->enabled) {
            return $query;
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        return app(ScopeSignalQueryToOwner::class)->handle($query, $owner);
    }
}
