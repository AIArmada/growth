<?php

declare(strict_types=1);

namespace AIArmada\Growth\Database\Factories;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variant>
 */
final class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'experiment_id' => function (): string {
                /** @var Experiment $experiment */
                $experiment = Experiment::factory()->create();

                return (string) $experiment->getKey();
            },
            'code' => Str::upper(Str::random(1)),
            'name' => ucfirst($this->faker->unique()->word()) . ' Variant',
            'description' => $this->faker->sentence(),
            'traffic_percentage' => 50,
            'position' => 0,
            'is_control' => false,
            'is_active' => true,
            'settings' => null,
            'owner_type' => null,
            'owner_id' => null,
        ];
    }

    public function global(): static
    {
        return $this->state([
            'experiment_id' => function (): string {
                /** @var Experiment $experiment */
                $experiment = OwnerContext::withOwner(null, fn (): Experiment => Experiment::factory()->global()->create());

                return (string) $experiment->getKey();
            },
            'owner_type' => null,
            'owner_id' => null,
        ]);
    }
}
