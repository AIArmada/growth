<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Exceptions\NoCurrentOwnerException;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeOverride;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Queries\ExperimentResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class RepairExperimentAssignment
{
    public const string UNCHANGED = 'unchanged';

    public const string CHANGED = 'changed';

    public const string QUARANTINED = 'quarantined';

    public function __construct(
        private ResolveExperimentAssignment $resolver,
        private ExperimentResolver $experimentResolver,
    ) {}

    public function handle(Assignment $assignment, bool $persist = true): string
    {
        return OwnerScopeOverride::withoutIncludeGlobal(function () use ($assignment, $persist): string {
            if (Assignment::ownerScopeConfig()->enabled) {
                OwnerContext::assertResolvedOrExplicitGlobal(
                    OwnerContext::resolveConfigured(Assignment::ownerScopeConfig()),
                    'Assignment repair requires a writable owner scope.',
                );
            }

            $repair = function () use ($assignment, $persist): string {
                $query = Assignment::query()->whereKey($assignment->getKey());

                if ($persist) {
                    $query->lockForUpdate();
                }

                $current = $query->first();

                if (! $current instanceof Assignment) {
                    throw new AuthorizationException('Assignment is not accessible in the current owner scope.');
                }

                return $this->repair($current, $persist);
            };

            return $persist ? $assignment->getConnection()->transaction($repair) : $repair();
        });
    }

    private function repair(Assignment $assignment, bool $persist): string
    {
        $assignmentOwner = $this->normalizedOwnerTuple($assignment->owner_type, $assignment->owner_id);

        if ($assignmentOwner === null) {
            return $this->quarantine($assignment, 'invalid_owner_tuple', $persist);
        }

        [$experiment, $resolutionFailure] = $this->resolveParentExperiment($assignment);

        if (! $experiment instanceof Experiment) {
            return $this->quarantine($assignment, $resolutionFailure ?? 'missing_parent', $persist);
        }

        $experimentOwner = $this->normalizedOwnerTuple($experiment->owner_type, $experiment->owner_id);

        if ($experimentOwner === null || $experimentOwner !== $assignmentOwner) {
            return $this->quarantine($assignment, 'owner_mismatch', $persist);
        }

        if ($experiment->status !== ExperimentStatus::Active) {
            return $this->quarantine($assignment, 'inactive_experiment', $persist);
        }

        $subjectKey = mb_trim((string) $assignment->subject_key);

        if ($subjectKey === '') {
            return $this->quarantine($assignment, 'empty_subject_key', $persist);
        }

        try {
            [$variant, $bucket] = $this->resolver->variantForSubject($experiment, $subjectKey);
        } catch (InvalidArgumentException) {
            return $this->quarantine($assignment, 'no_eligible_variant', $persist);
        }

        $metadata = $assignment->metadata ?? [];

        if ($assignment->variant_id === (string) $variant->getKey() && $assignment->bucket === $bucket && ! array_key_exists('repair_quarantine', $metadata)) {
            return self::UNCHANGED;
        }

        if ($persist) {
            unset($metadata['repair_quarantine']);
            $assignment->variant_id = (string) $variant->getKey();
            $assignment->bucket = $bucket;
            $assignment->metadata = $metadata;

            Assignment::query()->whereKey($assignment->getKey())->update($assignment->getDirty());
        }

        return self::CHANGED;
    }

    /**
     * @return array{0: Experiment|null, 1: string|null}
     */
    private function resolveParentExperiment(Assignment $assignment): array
    {
        try {
            return [$this->experimentResolver->resolve((string) $assignment->experiment_id), null];
        } catch (NoCurrentOwnerException $exception) {
            throw $exception;
        } catch (AuthorizationException | ModelNotFoundException) {
            return [null, 'inaccessible_parent'];
        } catch (InvalidArgumentException) {
            return [null, 'missing_parent'];
        }
    }

    /**
     * @return string|null Null when only one side of the tuple is present.
     */
    private function normalizedOwnerTuple(mixed $ownerType, mixed $ownerId): ?string
    {
        $type = is_scalar($ownerType) && (string) $ownerType !== '' ? (string) $ownerType : null;
        $id = is_scalar($ownerId) && (string) $ownerId !== '' ? (string) $ownerId : null;

        if (($type === null) !== ($id === null)) {
            return null;
        }

        if ($type === null) {
            return 'global';
        }

        return $type . '|' . $id;
    }

    private function quarantine(Assignment $assignment, string $reason, bool $persist): string
    {
        if ($persist) {
            $metadata = $assignment->metadata ?? [];
            $metadata['repair_quarantine'] = [
                'reason' => $reason,
                'recorded_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $assignment->metadata = $metadata;
            Assignment::query()->whereKey($assignment->getKey())->update($assignment->getDirty());
        }

        return self::QUARANTINED;
    }
}
