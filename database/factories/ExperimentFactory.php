<?php

declare(strict_types=1);

namespace AIArmada\Growth\Database\Factories;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Experiment>
 */
final class ExperimentFactory extends Factory
{
    protected $model = Experiment::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(3, true));
        $slug = Str::slug($name);

        return [
            'tracked_property_id' => function (): string {
                /** @var TrackedProperty $trackedProperty */
                $trackedProperty = TrackedProperty::query()->create([
                    'name' => 'Experiment Property ' . Str::random(6),
                    'slug' => 'experiment-property-' . Str::lower(Str::random(8)),
                    'write_key' => Str::random(40),
                    'type' => 'website',
                    'timezone' => 'UTC',
                    'currency' => 'MYR',
                    'is_active' => true,
                ]);

                return (string) $trackedProperty->getKey();
            },
            'name' => $name,
            'slug' => $slug,
            'description' => $this->faker->sentence(),
            'module_type' => 'ab_test',
            'status' => ExperimentStatus::Active,
            'goal_event_name' => 'order.paid',
            'goal_event_category' => 'conversion',
            'winner_metric' => 'revenue_per_visitor',
            'audience' => null,
            'settings' => null,
            'started_at' => CarbonImmutable::now(),
            'ended_at' => null,
        ];
    }

    public function global(): static
    {
        return $this->state([
            'tracked_property_id' => function (): string {
                /** @var TrackedProperty $trackedProperty */
                $trackedProperty = OwnerContext::withOwner(null, fn (): TrackedProperty => TrackedProperty::query()->create([
                    'name' => 'Global Property ' . Str::random(6),
                    'slug' => 'global-property-' . Str::lower(Str::random(8)),
                    'write_key' => Str::random(40),
                    'type' => 'website',
                    'timezone' => 'UTC',
                    'currency' => 'MYR',
                    'is_active' => true,
                    'owner_type' => null,
                    'owner_id' => null,
                ]));

                return (string) $trackedProperty->getKey();
            },
            'owner_type' => null,
            'owner_id' => null,
        ]);
    }
}
