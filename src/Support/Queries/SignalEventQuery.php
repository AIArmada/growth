<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class SignalEventQuery
{
    /**
     * @return Builder<SignalEvent>
     */
    public function forExperiment(Experiment $experiment): Builder
    {
        $query = SignalEvent::query()->where('tracked_property_id', $experiment->tracked_property_id);

        if (! SignalEvent::ownerScopeConfig()->enabled && ! Experiment::ownerScopeConfig()->enabled) {
            return $query;
        }

        $trackedProperty = $experiment->trackedProperty;

        if (! $trackedProperty instanceof TrackedProperty) {
            return $query->whereKey([]);
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        return app(ScopeSignalQueryToOwner::class)->handle(
            $query,
            $owner,
            includeGlobal: false,
        );
    }
}
