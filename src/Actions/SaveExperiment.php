<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Queries\ExperimentOwnership;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class SaveExperiment
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Experiment
    {
        return (new Experiment)->getConnection()->transaction(function () use ($data): Experiment {
            $propertyId = $data['tracked_property_id'] ?? null;
            $property = is_string($propertyId)
                ? ExperimentOwnership::scopeOwner(TrackedProperty::query(), writable: true)
                    ->whereKey($propertyId)
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $property instanceof TrackedProperty) {
                throw new AuthorizationException('Tracked property is not writable in the current owner scope.');
            }

            $record = new Experiment($this->attributes($data));
            $record->tracked_property_id = (string) $property->getKey();

            if (Experiment::ownerScopeConfig()->enabled) {
                $record->owner_type = $property->owner_type;
                $record->owner_id = $property->owner_id !== null ? (string) $property->owner_id : null;
            }

            if (! is_string($record->slug) || $record->slug === '') {
                $record->slug = Str::slug((string) ($data['name'] ?? 'experiment'));
            }

            if (isset($data['status'])) {
                $record->transitionTo($this->experimentStatus($data['status']));
            }

            $record->saveOrFail();

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): Experiment
    {
        return (new Experiment)->getConnection()->transaction(function () use ($id, $data): Experiment {
            $record = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('tracked_property_id', $data) && $data['tracked_property_id'] !== $record->tracked_property_id) {
                throw ValidationException::withMessages(['tracked_property_id' => 'The tracked property cannot be changed.']);
            }

            $record->fill($this->attributes($data));

            if (isset($data['status'])) {
                $status = $this->experimentStatus($data['status']);

                if ($record->status !== $status) {
                    try {
                        $record->transitionTo($status);
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['status' => $exception->getMessage()]);
                    }
                }
            }

            $record->saveOrFail();

            return $record;
        });
    }

    public function setRunning(string $id, bool $running): bool
    {
        return (new Experiment)->getConnection()->transaction(function () use ($id, $running): bool {
            $current = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($id)->lockForUpdate()->first();

            if (! $current instanceof Experiment) {
                throw new AuthorizationException('The growth experiment is not writable in the current owner scope.');
            }

            if (in_array($current->status, [ExperimentStatus::Concluded, ExperimentStatus::Archived], true)) {
                return false;
            }

            $status = $running ? ExperimentStatus::Active : ExperimentStatus::Paused;

            if ($current->status !== $status) {
                $current->transitionTo($status)->saveOrFail();
            }

            return $current->status === ExperimentStatus::Active;
        });
    }

    /** @param list<string> $ids */
    public function deleteMany(array $ids): void
    {
        (new Experiment)->getConnection()->transaction(function () use ($ids): void {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_STRING);

            $records = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($records->count() !== count($ids)) {
                throw new AuthorizationException('One or more selected growth records are not writable.');
            }

            foreach ($records as $record) {
                if ($record->delete() !== true) {
                    throw new RuntimeException('Growth record deletion failed.');
                }
            }
        });
    }

    private function experimentStatus(mixed $status): ExperimentStatus
    {
        if ($status instanceof ExperimentStatus) {
            return $status;
        }

        if (! is_string($status)) {
            throw ValidationException::withMessages(['status' => 'The experiment status is invalid.']);
        }

        try {
            return ExperimentStatus::from($status);
        } catch (ValueError) {
            throw ValidationException::withMessages(['status' => 'The experiment status is invalid.']);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return Arr::only($data, [
            'name', 'slug', 'description', 'module_type', 'goal_event_name',
            'goal_event_category', 'winner_metric', 'audience', 'settings',
        ]);
    }
}
