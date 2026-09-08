<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use Illuminate\Database\Eloquent\Builder;

final class VariantQuery
{
    /**
     * @return Builder<Variant>
     */
    public function forExperiment(Experiment $experiment, bool $activeOnly = false): Builder
    {
        $query = Variant::query()->where('experiment_id', $experiment->getKey());

        if ($activeOnly) {
            $query->active();
        }

        if (! Variant::ownerScopeConfig()->enabled) {
            return $query;
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        return $owner === null
            ? $query->globalOnly()
            : $query->forOwner($owner, includeGlobal: false);
    }
}
