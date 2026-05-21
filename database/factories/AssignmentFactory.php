<?php

declare(strict_types=1);

namespace AIArmada\Growth\Database\Factories;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
final class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        /** @var Variant $variant */
        $variant = Variant::factory()->create();

        $assignedAt = CarbonImmutable::now();

        return [
            'experiment_id' => (string) $variant->experiment_id,
            'variant_id' => (string) $variant->getKey(),
            'signal_identity_id' => null,
            'signal_session_id' => null,
            'subject_key' => 'anonymous:' . $this->faker->uuid(),
            'bucket' => 0,
            'metadata' => null,
            'assigned_at' => $assignedAt,
            'first_exposed_at' => $assignedAt,
            'last_seen_at' => $assignedAt,
            'owner_type' => null,
            'owner_id' => null,
        ];
    }

    public function global(): static
    {
        return $this->state(function (): array {
            /** @var Variant $variant */
            $variant = OwnerContext::withOwner(null, fn (): Variant => Variant::factory()->global()->create());
            $assignedAt = CarbonImmutable::now();

            return [
                'experiment_id' => (string) $variant->experiment_id,
                'variant_id' => (string) $variant->getKey(),
                'assigned_at' => $assignedAt,
                'first_exposed_at' => $assignedAt,
                'last_seen_at' => $assignedAt,
                'owner_type' => null,
                'owner_id' => null,
            ];
        });
    }
}
