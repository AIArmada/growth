<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Features\Ingestion\Api\TrackedPropertyDeleting;

final readonly class DeletePropertyExperiments
{
    public function handle(TrackedPropertyDeleting $event): void
    {
        $model = new Experiment;
        $connection = $model->getConnection();

        if (! $connection->getSchemaBuilder()->hasTable($model->getTable())) {
            return;
        }

        $owner = OwnerContext::fromTypeAndIdOrFail($event->ownerType, $event->ownerId);

        OwnerContext::withOwner($owner, function () use ($event, $connection): void {
            $connection->transaction(function () use ($event): void {
                $query = Experiment::query()
                    ->withoutOwnerScope()
                    ->where('tracked_property_id', $event->propertyId);

                if ($event->ownerType === null) {
                    $query->whereNull('owner_type')->whereNull('owner_id');
                } else {
                    $query->where('owner_type', $event->ownerType)->where('owner_id', $event->ownerId);
                }

                $query->chunkById(500, static function ($experiments): void {
                    foreach ($experiments as $experiment) {
                        $experiment->delete();
                    }
                });
            });
        });
    }
}
