<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support\Queries;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;

final class SignalEventQuery
{
    /**
     * @return Builder<SignalEvent>
     */
    public function forExperiment(Experiment $experiment): Builder
    {
        if (! SignalEvent::ownerScopeConfig()->enabled && ! Experiment::ownerScopeConfig()->enabled) {
            return SignalEvent::query();
        }

        $trackedProperty = $experiment->trackedProperty;

        if (! $trackedProperty instanceof TrackedProperty) {
            return SignalEvent::query()->whereKey([]);
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        return app(ScopeSignalQueryToOwner::class)->handle(
            SignalEvent::query(),
            $owner,
            includeGlobal: false,
        );
    }
}
