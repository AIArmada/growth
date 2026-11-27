<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Queries\ExperimentOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class DeleteExperimentDependents
{
    public function handle(Experiment $experiment): void
    {
        if (! $experiment->exists) {
            throw new InvalidArgumentException('Experiment dependents require a persisted experiment.');
        }

        $connection = $experiment->getConnection();

        $connection->transaction(function () use ($experiment): void {
            $parent = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($experiment->getKey())
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof Experiment) {
                throw new AuthorizationException('Experiment dependents cannot be deleted outside the writable owner scope.');
            }

            $variants = Variant::query()
                ->withoutOwnerScope()
                ->where('experiment_id', $parent->getKey());
            ExperimentOwnership::constrainToOwnerTuple($variants, $parent->owner_type, $parent->owner_id);

            $variants->orderBy('id')->eachById(static function (Variant $variant): void {
                $variant->delete();
            });

            $assignments = Assignment::query()
                ->withoutOwnerScope()
                ->where('experiment_id', $parent->getKey());
            ExperimentOwnership::constrainToOwnerTuple($assignments, $parent->owner_type, $parent->owner_id);

            $assignments->delete();
        });
    }
}
