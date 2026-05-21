<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeConfig;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ResolveExperimentAssignment
{
    private const SUBJECT_KEY_MAX_LENGTH = 255;

    private const ANONYMOUS_SUBJECT_KEY_PREFIX = 'anonymous:';

    private const HASHED_ANONYMOUS_SUBJECT_KEY_PREFIX = 'anonymous:sha256:';

    public function handle(
        Experiment $experiment,
        ?SignalIdentity $identity = null,
        ?SignalSession $session = null,
        ?string $anonymousId = null,
    ): Assignment {
        $experiment = $this->resolveExperimentForCurrentOwner($experiment);
        $this->assertExperimentCanReceiveAssignments($experiment);

        if ($experiment->status !== ExperimentStatus::Active) {
            throw new InvalidArgumentException('Assignments can only be resolved for active experiments.');
        }

        [$identity, $session] = $this->validateSignalReferences($experiment, $identity, $session);

        $candidateKeys = $this->candidateSubjectKeys($identity, $session, $anonymousId);

        if ($candidateKeys === []) {
            throw new InvalidArgumentException('A signal identity, signal session, or anonymous id is required to resolve an assignment.');
        }

        return DB::transaction(function () use ($candidateKeys, $experiment, $identity, $session): Assignment {
            $matchingAssignments = $this->matchingAssignments($experiment, $identity, $session, $candidateKeys);

            if ($matchingAssignments->isNotEmpty()) {
                return $this->touchExistingAssignment($experiment, $matchingAssignments, $identity, $session, $candidateKeys);
            }

            return $this->createAssignment($experiment, $identity, $session, $candidateKeys);
        });
    }

    /**
     * @return list<string>
     */
    private function candidateSubjectKeys(
        ?SignalIdentity $identity,
        ?SignalSession $session,
        ?string $anonymousId,
    ): array {
        $candidateKeys = [];

        if ($identity instanceof SignalIdentity) {
            $candidateKeys[] = 'identity:' . (string) $identity->getKey();
        }

        if ($session instanceof SignalSession) {
            $candidateKeys[] = 'session:' . (string) $session->getKey();
        }

        if (is_string($anonymousId)) {
            $anonymousSubjectKey = $this->anonymousSubjectKey($anonymousId);

            if ($anonymousSubjectKey !== null) {
                $candidateKeys[] = $anonymousSubjectKey;
            }
        }

        return array_values(array_unique($candidateKeys));
    }

    private function anonymousSubjectKey(string $anonymousId): ?string
    {
        $normalizedAnonymousId = mb_trim($anonymousId);

        if ($normalizedAnonymousId === '') {
            return null;
        }

        $subjectKey = self::ANONYMOUS_SUBJECT_KEY_PREFIX . $normalizedAnonymousId;

        if (mb_strlen($subjectKey) <= self::SUBJECT_KEY_MAX_LENGTH) {
            return $subjectKey;
        }

        return self::HASHED_ANONYMOUS_SUBJECT_KEY_PREFIX . hash('sha256', $normalizedAnonymousId);
    }

    /**
     * @param  list<string>  $candidateKeys
     * @return EloquentCollection<int, Assignment>
     */
    private function matchingAssignments(
        Experiment $experiment,
        ?SignalIdentity $identity,
        ?SignalSession $session,
        array $candidateKeys,
    ): EloquentCollection {
        /** @var EloquentCollection<int, Assignment> $assignments */
        $assignments = $this->assignmentQuery($experiment)
            ->where('experiment_id', $experiment->getKey())
            ->where(function (Builder $query) use ($candidateKeys, $identity, $session): void {
                if ($identity instanceof SignalIdentity) {
                    $query->orWhere('signal_identity_id', $identity->getKey());
                }

                if ($session instanceof SignalSession) {
                    $query->orWhere('signal_session_id', $session->getKey());
                }

                foreach ($candidateKeys as $candidateKey) {
                    $query->orWhere('subject_key', $candidateKey);
                }
            })
            ->lockForUpdate()
            ->get();

        return $assignments;
    }

    /**
     * @param  EloquentCollection<int, Assignment>  $matchingAssignments
     * @param  list<string>  $candidateKeys
     */
    private function touchExistingAssignment(
        Experiment $experiment,
        EloquentCollection $matchingAssignments,
        ?SignalIdentity $identity,
        ?SignalSession $session,
        array $candidateKeys,
        bool $retryOnConflict = true,
    ): Assignment {
        $assignment = $this->canonicalAssignment($matchingAssignments);
        $duplicateAssignments = $matchingAssignments->reject(fn (Assignment $candidate): bool => $candidate->is($assignment));
        $lastSeenAt = CarbonImmutable::now();
        $resolvedIdentityId = $this->resolvedIdentifier($matchingAssignments, $identity, 'signal_identity_id');
        $resolvedSessionId = $this->resolvedIdentifier($matchingAssignments, $session, 'signal_session_id');
        $earliestAssignedAt = $this->earliestTimestamp($matchingAssignments, ['assigned_at']);
        $earliestFirstExposedAt = $this->earliestTimestamp($matchingAssignments, ['first_exposed_at', 'assigned_at']);
        $latestSeenAt = $this->latestTimestamp($matchingAssignments, ['last_seen_at', 'first_exposed_at', 'assigned_at']) ?? $lastSeenAt;

        try {
            $duplicateAssignments->each(static fn (Assignment $duplicate): bool => (bool) $duplicate->delete());

            if ($resolvedIdentityId !== null && $assignment->signal_identity_id === null) {
                $assignment->signal_identity_id = $resolvedIdentityId;
            }

            if ($resolvedSessionId !== null && $assignment->signal_session_id === null) {
                $assignment->signal_session_id = $resolvedSessionId;
            }

            $assignment->assigned_at = $earliestAssignedAt ?? $assignment->assigned_at ?? $lastSeenAt;
            $assignment->first_exposed_at = $earliestFirstExposedAt ?? $assignment->first_exposed_at ?? $assignment->assigned_at;
            $assignment->last_seen_at = $latestSeenAt->greaterThan($lastSeenAt) ? $latestSeenAt : $lastSeenAt;
            $assignment->save();
        } catch (QueryException $exception) {
            if (! $retryOnConflict || ! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $reloadedAssignments = $this->matchingAssignments($experiment, $identity, $session, $candidateKeys);

            if ($reloadedAssignments->isEmpty()) {
                throw $exception;
            }

            return $this->touchExistingAssignment($experiment, $reloadedAssignments, $identity, $session, $candidateKeys, false);
        }

        return $assignment->fresh(['variant', 'experiment']) ?? $assignment;
    }

    /**
     * @param  list<string>  $candidateKeys
     */
    private function createAssignment(
        Experiment $experiment,
        ?SignalIdentity $identity,
        ?SignalSession $session,
        array $candidateKeys,
    ): Assignment {
        $subjectKey = $candidateKeys[0];
        [$variant, $bucket] = $this->pickVariant($experiment, $subjectKey);
        $assignedAt = CarbonImmutable::now();

        $assignment = new Assignment([
            'experiment_id' => $experiment->getKey(),
            'variant_id' => $variant->getKey(),
            'signal_identity_id' => $identity?->getKey(),
            'signal_session_id' => $session?->getKey(),
            'subject_key' => $subjectKey,
            'bucket' => $bucket,
            'assigned_at' => $assignedAt,
            'first_exposed_at' => $assignedAt,
            'last_seen_at' => $assignedAt,
            'owner_type' => $experiment->owner_type,
            'owner_id' => $experiment->owner_id,
        ]);

        try {
            $assignment->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $matchingAssignments = $this->matchingAssignments($experiment, $identity, $session, $candidateKeys);

            if ($matchingAssignments->isEmpty()) {
                throw $exception;
            }

            return $this->touchExistingAssignment($experiment, $matchingAssignments, $identity, $session, $candidateKeys);
        }

        return $assignment->fresh(['variant', 'experiment']) ?? $assignment;
    }

    /**
     * @return array{0: Variant, 1: int}
     */
    private function pickVariant(Experiment $experiment, string $subjectKey): array
    {
        $variants = $this->variantQuery($experiment)
            ->where('experiment_id', $experiment->getKey())
            ->active()
            ->where('traffic_percentage', '>', 0)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        if ($variants->isEmpty()) {
            throw new InvalidArgumentException('At least one active variant with positive traffic is required.');
        }

        $totalWeight = $variants->sum('traffic_percentage');
        $bucket = $this->bucketFor($experiment, $subjectKey, $totalWeight);
        $cursor = 0;

        foreach ($variants as $variant) {
            $cursor += (int) $variant->traffic_percentage;

            if ($bucket < $cursor) {
                return [$variant, $bucket];
            }
        }

        $lastVariant = $variants->last();

        if (! $lastVariant instanceof Variant) {
            throw new InvalidArgumentException('Unable to resolve an experiment variant.');
        }

        return [$lastVariant, $bucket];
    }

    private function bucketFor(Experiment $experiment, string $subjectKey, int $totalWeight): int
    {
        $hash = (int) sprintf('%u', crc32($experiment->getKey() . '|' . $subjectKey));

        return $hash % $totalWeight;
    }

    /**
     * @param  EloquentCollection<int, Assignment>  $matchingAssignments
     */
    private function canonicalAssignment(EloquentCollection $matchingAssignments): Assignment
    {
        /** @var Assignment|null $assignment */
        $assignment = $matchingAssignments
            ->sort(function (Assignment $left, Assignment $right): int {
                $leftAssignedAt = $left->assigned_at?->getTimestamp() ?? PHP_INT_MAX;
                $rightAssignedAt = $right->assigned_at?->getTimestamp() ?? PHP_INT_MAX;

                return $leftAssignedAt <=> $rightAssignedAt ?: strcmp((string) $left->getKey(), (string) $right->getKey());
            })
            ->first();

        if (! $assignment instanceof Assignment) {
            throw new InvalidArgumentException('Unable to resolve an existing experiment assignment.');
        }

        return $assignment;
    }

    /**
     * @param  EloquentCollection<int, Assignment>  $matchingAssignments
     */
    private function resolvedIdentifier(EloquentCollection $matchingAssignments, ?Model $preferred, string $attribute): ?string
    {
        if ($preferred instanceof Model) {
            return (string) $preferred->getKey();
        }

        $existingValue = $matchingAssignments
            ->pluck($attribute)
            ->first(static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        return is_scalar($existingValue) ? (string) $existingValue : null;
    }

    /**
     * @param  EloquentCollection<int, Assignment>  $matchingAssignments
     * @param  list<string>  $attributes
     */
    private function earliestTimestamp(EloquentCollection $matchingAssignments, array $attributes): ?CarbonImmutable
    {
        /** @var Collection<int, CarbonImmutable> $timestamps */
        $timestamps = $matchingAssignments
            ->flatMap(function (Assignment $assignment) use ($attributes): array {
                $resolved = [];

                foreach ($attributes as $attribute) {
                    $value = $assignment->getAttribute($attribute);

                    if ($value instanceof CarbonImmutable) {
                        $resolved[] = $value;
                    }
                }

                return $resolved;
            })
            ->sortBy(static fn (CarbonImmutable $timestamp): int => $timestamp->getTimestamp())
            ->values();

        $timestamp = $timestamps->first();

        return $timestamp instanceof CarbonImmutable ? $timestamp : null;
    }

    /**
     * @param  EloquentCollection<int, Assignment>  $matchingAssignments
     * @param  list<string>  $attributes
     */
    private function latestTimestamp(EloquentCollection $matchingAssignments, array $attributes): ?CarbonImmutable
    {
        /** @var Collection<int, CarbonImmutable> $timestamps */
        $timestamps = $matchingAssignments
            ->flatMap(function (Assignment $assignment) use ($attributes): array {
                $resolved = [];

                foreach ($attributes as $attribute) {
                    $value = $assignment->getAttribute($attribute);

                    if ($value instanceof CarbonImmutable) {
                        $resolved[] = $value;
                    }
                }

                return $resolved;
            })
            ->sortByDesc(static fn (CarbonImmutable $timestamp): int => $timestamp->getTimestamp())
            ->values();

        $timestamp = $timestamps->first();

        return $timestamp instanceof CarbonImmutable ? $timestamp : null;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint');
    }

    /**
     * @return array{0: SignalIdentity|null, 1: SignalSession|null}
     */
    private function validateSignalReferences(
        Experiment $experiment,
        ?SignalIdentity $identity,
        ?SignalSession $session,
    ): array {
        if ($identity instanceof SignalIdentity) {
            $identity = $this->resolveSignalIdentityForExperiment(
                $experiment,
                (string) $identity->getKey(),
                'Signal identity is not accessible in the current owner scope.',
            );

            if ($identity->tracked_property_id !== $experiment->tracked_property_id) {
                throw new InvalidArgumentException('Signal identity must belong to the same tracked property as the experiment.');
            }
        }

        if ($session instanceof SignalSession) {
            $session = $this->resolveSignalSessionForExperiment(
                $experiment,
                (string) $session->getKey(),
                'Signal session is not accessible in the current owner scope.',
            );

            if ($session->tracked_property_id !== $experiment->tracked_property_id) {
                throw new InvalidArgumentException('Signal session must belong to the same tracked property as the experiment.');
            }
        }

        if ($identity instanceof SignalIdentity && $session instanceof SignalSession) {
            $sessionIdentityId = is_scalar($session->signal_identity_id) && (string) $session->signal_identity_id !== ''
                ? (string) $session->signal_identity_id
                : null;

            if ($sessionIdentityId !== null && $sessionIdentityId !== (string) $identity->getKey()) {
                throw new InvalidArgumentException('Signal session must match the provided signal identity.');
            }
        }

        return [$identity, $session];
    }

    private function resolveExperimentForCurrentOwner(Experiment $experiment): Experiment
    {
        return app(ResolveAccessibleExperiment::class)->handle(
            $experiment,
            'Growth experiment is not accessible in the current owner scope.',
        );
    }

    private function assertExperimentCanReceiveAssignments(Experiment $experiment): void
    {
        if (! Assignment::ownerScopeConfig()->enabled) {
            return;
        }

        if (! $experiment->isGlobal()) {
            return;
        }

        if (OwnerContext::isExplicitGlobal()) {
            return;
        }

        throw new AuthorizationException('Explicit global owner context is required to resolve assignments for global growth experiments.');
    }

    /**
     * @return Builder<Assignment>
     */
    private function assignmentQuery(Experiment $experiment): Builder
    {
        if (! Assignment::ownerScopeConfig()->enabled) {
            return Assignment::query();
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        if ($owner === null) {
            return Assignment::query()->globalOnly();
        }

        return Assignment::query()->forOwner($owner, includeGlobal: false);
    }

    /**
     * @return Builder<Variant>
     */
    private function variantQuery(Experiment $experiment): Builder
    {
        if (! Variant::ownerScopeConfig()->enabled) {
            return Variant::query();
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        if ($owner === null) {
            return Variant::query()->globalOnly();
        }

        return Variant::query()->forOwner($owner, includeGlobal: false);
    }

    private function resolveSignalIdentityForExperiment(Experiment $experiment, string $id, string $message): SignalIdentity
    {
        $identity = $this->resolveSignalModelForExperiment(
            SignalIdentity::class,
            $experiment,
            $id,
            $message,
        );

        if (! $identity instanceof SignalIdentity) {
            throw new InvalidArgumentException($message);
        }

        return $identity;
    }

    private function resolveSignalSessionForExperiment(Experiment $experiment, string $id, string $message): SignalSession
    {
        $session = $this->resolveSignalModelForExperiment(
            SignalSession::class,
            $experiment,
            $id,
            $message,
        );

        if (! $session instanceof SignalSession) {
            throw new InvalidArgumentException($message);
        }

        return $session;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    private function resolveSignalModelForExperiment(string $modelClass, Experiment $experiment, string $id, string $message): Model
    {
        /** @var Builder<TModel> $query */
        $query = $modelClass::query();
        $modelOwnerScopingEnabled = $this->modelOwnerScopingEnabled($modelClass);

        if (Experiment::ownerScopeConfig()->enabled || $modelOwnerScopingEnabled) {
            $query = app(ScopeSignalQueryToOwner::class)->handle(
                $query,
                $this->signalOwnerForExperiment($experiment),
            );
        }

        $model = $query->whereKey($id)->first();

        if ($model instanceof Model) {
            return $model;
        }

        if (Experiment::ownerScopeConfig()->enabled || $modelOwnerScopingEnabled) {
            throw new AuthorizationException($message);
        }

        throw new InvalidArgumentException($message);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function modelOwnerScopingEnabled(string $modelClass): bool
    {
        if (! method_exists($modelClass, 'ownerScopeConfig')) {
            return false;
        }

        $config = $modelClass::ownerScopeConfig();

        return $config instanceof OwnerScopeConfig && $config->enabled;
    }

    private function signalOwnerForExperiment(Experiment $experiment): ?Model
    {
        if (Experiment::ownerScopeConfig()->enabled) {
            return OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);
        }

        $trackedProperty = $this->trackedPropertyForExperiment($experiment);

        if ($trackedProperty instanceof TrackedProperty) {
            return OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);
        }

        return null;
    }

    private function trackedPropertyForExperiment(Experiment $experiment): ?TrackedProperty
    {
        if ($experiment->relationLoaded('trackedProperty') && $experiment->trackedProperty instanceof TrackedProperty) {
            return $experiment->trackedProperty;
        }

        $trackedProperty = TrackedProperty::query()
            ->whereKey((string) $experiment->tracked_property_id)
            ->first();

        return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
    }
}
