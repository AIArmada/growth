<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\Growth\Enums\ExperimentModuleType;
use AIArmada\Growth\Enums\VariantStatus;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Queries\ExperimentOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class SaveVariant
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Variant
    {
        return (new Variant)->getConnection()->transaction(function () use ($data): Variant {
            $experimentId = $data['experiment_id'] ?? null;

            if (! is_string($experimentId)) {
                throw new AuthorizationException('A writable experiment is required.');
            }

            $experiment = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($experimentId)->lockForUpdate()->firstOrFail();

            $record = new Variant($this->attributes($data, $experiment));
            $record->experiment_id = (string) $experiment->getKey();

            if (isset($data['status'])) {
                $record->transitionTo($this->variantStatus($data['status']));
            }

            $record->saveOrFail();

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): Variant
    {
        return (new Variant)->getConnection()->transaction(function () use ($id, $data): Variant {
            $record = ExperimentOwnership::scopeVariants(Variant::query(), writable: true)
                ->whereKey($id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('experiment_id', $data) && $data['experiment_id'] !== $record->experiment_id) {
                throw ValidationException::withMessages(['experiment_id' => 'The parent experiment cannot be changed.']);
            }

            $experiment = ExperimentOwnership::scopeExperiments(Experiment::query(), writable: true)
                ->whereKey($record->experiment_id)->firstOrFail();

            $record->fill($this->attributes($data, $experiment));

            if (isset($data['status'])) {
                $status = $this->variantStatus($data['status']);

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

    /** @param list<string> $ids */
    public function deleteMany(array $ids): void
    {
        (new Variant)->getConnection()->transaction(function () use ($ids): void {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_STRING);

            $records = ExperimentOwnership::scopeVariants(Variant::query(), writable: true)
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

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Experiment $experiment): array
    {
        $data = Arr::only($data, ['code', 'name', 'description', 'traffic_percentage', 'position', 'is_control', 'settings']);

        if (array_key_exists('settings', $data)) {
            $data['settings'] = $this->normalizeSettings($experiment->module_type, $data['settings']);
        }

        return $data;
    }

    private function variantStatus(mixed $status): VariantStatus
    {
        if ($status instanceof VariantStatus) {
            return $status;
        }

        if (! is_string($status)) {
            throw ValidationException::withMessages(['status' => 'The variant status is invalid.']);
        }

        try {
            return VariantStatus::from($status);
        } catch (ValueError) {
            throw ValidationException::withMessages(['status' => 'The variant status is invalid.']);
        }
    }

    /** @return array<string, mixed>|null */
    public function normalizeSettings(string $moduleType, mixed $settings): ?array
    {
        $allowed = match ($moduleType) {
            ExperimentModuleType::SalesPageTest->value => ['destination_url', 'headline', 'cta_copy'],
            ExperimentModuleType::FunnelTest->value => ['entry_path', 'step_key', 'offer_label'],
            ExperimentModuleType::PricingTest->value => ['price_label', 'price_minor', 'currency'],
            default => [],
        };

        $normalized = Arr::only(is_array($settings) ? $settings : [], $allowed);

        return $normalized === [] ? null : $normalized;
    }
}
