<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use Illuminate\Database\Eloquent\Builder;

final class AssignmentQuery
{
    /**
     * @return Builder<Assignment>
     */
    public function forExperiment(Experiment $experiment): Builder
    {
        $query = Assignment::query()->where('experiment_id', $experiment->getKey());

        if (! Assignment::ownerScopeConfig()->enabled) {
            return $query;
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        return $owner === null
            ? $query->globalOnly()
            : $query->forOwner($owner, includeGlobal: false);
    }
}
