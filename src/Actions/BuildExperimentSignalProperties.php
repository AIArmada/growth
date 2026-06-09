<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Growth\Enums\ResolveStrategy;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Support\Context\ExperimentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class BuildExperimentSignalProperties
{
    /**
     * @return array<string, string>
     */
    public function handle(Assignment $assignment): array
    {
        return $this->contextForAssignment($assignment);
    }

    /**
     * @return array<string, string>
     */
    public function contextForAssignment(Assignment $assignment): array
    {
        $assignment = $this->resolveAssignmentForCurrentScope($assignment);
        $experiment = $this->resolveExperiment($assignment);
        $variant = $this->resolveVariant($assignment);

        if (! $experiment instanceof Experiment || ! $variant instanceof Variant) {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        if ($variant->experiment_id !== $experiment->getKey()) {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        return [
            'experiment_id' => (string) $assignment->experiment_id,
            'experiment_slug' => (string) $experiment->slug,
            'variant_id' => (string) $assignment->variant_id,
            'variant_code' => (string) $variant->code,
            'assignment_id' => (string) $assignment->getKey(),
            'module_type' => (string) $experiment->module_type,
        ];
    }

    /**
     * @param  iterable<Assignment>  $assignments
     * @return list<array<string, string>>
     */
    public function contextsForAssignments(iterable $assignments): array
    {
        $contexts = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof Assignment) {
                continue;
            }

            try {
                $contexts[] = $this->contextForAssignment($assignment);
            } catch (AuthorizationException | InvalidArgumentException) {
                continue;
            }
        }

        return $contexts;
    }

    private function resolveAssignmentForCurrentScope(Assignment $assignment): Assignment
    {
        $assignmentId = (string) $assignment->getKey();

        if ($assignmentId === '') {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        $config = Assignment::ownerScopeConfig();

        if ($config->enabled) {
            /** @var Assignment $resolvedAssignment */
            $resolvedAssignment = OwnerWriteGuard::findOrFailForOwner(
                Assignment::class,
                $assignmentId,
                OwnerContext::CURRENT,
                $config->includeGlobal,
                'Assignment is not accessible in the current owner scope.',
            );

            return $resolvedAssignment;
        }

        $resolvedAssignment = Assignment::query()
            ->whereKey($assignmentId)
            ->first();

        if (! $resolvedAssignment instanceof Assignment) {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        $experiment = Experiment::query()
            ->whereKey($resolvedAssignment->experiment_id)
            ->first();

        if (! $experiment instanceof Experiment) {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        if ((Assignment::ownerScopeConfig()->enabled || Experiment::ownerScopeConfig()->enabled)
            && ! $this->matchesAssignmentOwner($resolvedAssignment, $experiment)) {
            throw new InvalidArgumentException('Assignment experiment context could not be resolved.');
        }

        $accessibleExperiment = app(ExperimentResolver::class)->resolve(
            (string) $experiment->getKey(),
            ResolveStrategy::Readable,
            'Assignment is not accessible in the current owner scope.',
        );

        $resolvedAssignment->setRelation('experiment', $accessibleExperiment);

        return $resolvedAssignment;
    }

    private function resolveExperiment(Assignment $assignment): ?Experiment
    {
        $experiment = null;

        if (
            $assignment->relationLoaded('experiment')
            && $assignment->experiment instanceof Experiment
            && $this->matchesAssignmentOwner($assignment, $assignment->experiment)
        ) {
            $experiment = $assignment->experiment;
        }

        if (! $experiment instanceof Experiment) {
            $experiment = $this->queryForAssignmentOwner(Experiment::query(), $assignment)
                ->whereKey($assignment->experiment_id)
                ->first();
        }

        if (! $experiment instanceof Experiment) {
            return null;
        }

        return app(ExperimentResolver::class)->resolve(
            (string) $experiment->getKey(),
            ResolveStrategy::Readable,
            'Assignment is not accessible in the current owner scope.',
        );
    }

    private function resolveVariant(Assignment $assignment): ?Variant
    {
        if (
            $assignment->relationLoaded('variant')
            && $assignment->variant instanceof Variant
            && $assignment->variant->experiment_id === $assignment->experiment_id
            && $this->matchesAssignmentOwner($assignment, $assignment->variant)
        ) {
            return $assignment->variant;
        }

        $variant = $this->queryForAssignmentOwner(Variant::query(), $assignment)
            ->whereKey($assignment->variant_id)
            ->first();

        return $variant instanceof Variant ? $variant : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function queryForAssignmentOwner(Builder $query, Assignment $assignment): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $query->getModel()::class;

        if (! method_exists($modelClass, 'ownerScopeConfig')) {
            return $query;
        }

        $config = $modelClass::ownerScopeConfig();

        if (! $config->enabled) {
            return $query;
        }

        $owner = OwnerContext::fromTypeAndId($assignment->owner_type, $assignment->owner_id);

        if ($owner === null) {
            /** @phpstan-ignore-next-line dynamic Eloquent scope */
            return $query->globalOnly();
        }

        /** @phpstan-ignore-next-line dynamic Eloquent scope */
        return $query->forOwner($owner, includeGlobal: false);
    }

    private function matchesAssignmentOwner(Assignment $assignment, Model $model): bool
    {
        return $this->stringAttribute($assignment, 'owner_type') === $this->stringAttribute($model, 'owner_type')
            && $this->stringAttribute($assignment, 'owner_id') === $this->stringAttribute($model, 'owner_id');
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return (string) $value;
    }
}
