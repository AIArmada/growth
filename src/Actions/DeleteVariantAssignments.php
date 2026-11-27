<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Queries\ExperimentOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class DeleteVariantAssignments
{
    public function handle(Variant $variant): void
    {
        if (! $variant->exists) {
            throw new InvalidArgumentException('Variant assignment cleanup requires a persisted variant.');
        }

        $variant->getConnection()->transaction(function () use ($variant): void {
            $parent = ExperimentOwnership::scopeVariants(Variant::query(), writable: true)
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof Variant) {
                throw new AuthorizationException('Variant assignments cannot be deleted outside the writable owner scope.');
            }

            $assignments = Assignment::query()
                ->withoutOwnerScope()
                ->where('variant_id', $parent->getKey())
                ->where('experiment_id', $parent->experiment_id);
            ExperimentOwnership::constrainToOwnerTuple($assignments, $parent->owner_type, $parent->owner_id);

            $assignments->delete();
        });
    }
}
