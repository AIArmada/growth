<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support;

use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Pure experiment metric calculation. It performs no persistence or queries.
 */
final class MetricsCalculator
{
    /**
     * @param  Collection<int, Variant>  $variants
     * @param  Collection<string, Collection<int, Assignment>>  $assignments
     * @param  Collection<int, SignalEvent>  $events
     * @return array{winner_variant_id: string|null, totals: array{assignments: int, checkout_starts: int, purchases: int, refunds: int, revenue_minor: int}, variants: array<int, array<string, float|int|string|null>>}
     */
    public function calculate(
        Experiment $experiment,
        Collection $variants,
        Collection $assignments,
        Collection $events,
        string $experimentCurrency,
        string $checkoutStartedEventName,
        string $purchaseEventName,
        string $refundEventName,
    ): array {
        $groupedEvents = $this->groupEventsByVariant($events, $experiment);
        $variantMetrics = $variants
            ->map(fn (Variant $variant): array => $this->variantMetrics(
                $variant,
                $assignments,
                $groupedEvents,
                $experimentCurrency,
                $checkoutStartedEventName,
                $purchaseEventName,
                $refundEventName,
            ))
            ->values()
            ->all();

        $totals = [
            'assignments' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['assignments'], $variantMetrics)),
            'checkout_starts' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['checkout_starts'], $variantMetrics)),
            'purchases' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['purchases'], $variantMetrics)),
            'refunds' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['refunds'], $variantMetrics)),
            'revenue_minor' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['revenue_minor'], $variantMetrics)),
        ];

        $winnerVariantId = null;

        if ($totals['assignments'] > 0 && $this->hasWinnerMetricData($variantMetrics, (string) $experiment->winner_metric)) {
            $winningMetrics = collect($variantMetrics)
                ->sortByDesc(fn (array $metrics): array => [
                    (float) ($metrics[$experiment->winner_metric] ?? 0),
                    (int) $metrics['assignments'],
                    -1 * (int) $metrics['position'],
                ])
                ->first();

            $winnerVariantId = is_array($winningMetrics) && is_string($winningMetrics['variant_id'] ?? null)
                ? $winningMetrics['variant_id']
                : null;
        }

        return [
            'winner_variant_id' => $winnerVariantId,
            'totals' => $totals,
            'variants' => $variantMetrics,
        ];
    }

    /**
     * @param  Collection<int, SignalEvent>  $events
     * @return Collection<string, Collection<int, array{event: SignalEvent, context: array{experiment_id: string, variant_id: string, assignment_id?: string}}>>
     */
    private function groupEventsByVariant(Collection $events, Experiment $experiment): Collection
    {
        return $events
            ->map(function (SignalEvent $event) use ($experiment): ?array {
                $context = $this->resolveContextForExperiment($event, $experiment);

                return $context === null ? null : ['event' => $event, 'context' => $context];
            })
            ->filter()
            ->values()
            ->groupBy(fn (array $payload): string => (string) Arr::get($payload, 'context.variant_id', ''));
    }

    /**
     * @param  Collection<string, Collection<int, Assignment>>  $assignments
     * @param  Collection<string, Collection<int, array{event: SignalEvent, context: array{experiment_id: string, variant_id: string, assignment_id?: string}}>>  $events
     * @return array<string, float|int|string|null>
     */
    private function variantMetrics(
        Variant $variant,
        Collection $assignments,
        Collection $events,
        string $experimentCurrency,
        string $checkoutStartedEventName,
        string $purchaseEventName,
        string $refundEventName,
    ): array {
        /** @var Collection<int, Assignment> $variantAssignments */
        $variantAssignments = $assignments->get((string) $variant->getKey(), collect());
        /** @var Collection<int, array{event: SignalEvent, context: array{experiment_id: string, variant_id: string, assignment_id?: string}}> $variantEvents */
        $variantEvents = $events->get((string) $variant->getKey(), collect());
        $checkoutStarts = $variantEvents->filter(fn (array $payload): bool => $payload['event']->event_name === $checkoutStartedEventName)->count();
        /** @var Collection<int, array{event: SignalEvent, context: array{experiment_id: string, variant_id: string, assignment_id?: string}}> $purchaseEvents */
        $purchaseEvents = $variantEvents
            ->filter(fn (array $payload): bool => $payload['event']->event_name === $purchaseEventName)
            ->values();
        /** @var Collection<int, array{event: SignalEvent, context: array{experiment_id: string, variant_id: string, assignment_id?: string}}> $refundEvents */
        $refundEvents = $variantEvents
            ->filter(fn (array $payload): bool => $payload['event']->event_name === $refundEventName)
            ->values();
        $purchaseRevenue = (int) $purchaseEvents
            ->filter(fn (array $payload): bool => $this->eventMatchesCurrency($payload['event'], $experimentCurrency))
            ->sum(fn (array $payload): int => (int) $payload['event']->revenue_minor);
        $refundRevenue = (int) $refundEvents
            ->filter(fn (array $payload): bool => $this->eventMatchesCurrency($payload['event'], $experimentCurrency))
            ->sum(fn (array $payload): int => (int) $payload['event']->revenue_minor);
        $assignmentCount = $variantAssignments->count();
        $convertingAssignments = $purchaseEvents
            ->map(function (array $payload): ?string {
                $assignmentId = Arr::get($payload, 'context.assignment_id');

                return is_scalar($assignmentId) && (string) $assignmentId !== '' ? (string) $assignmentId : null;
            })
            ->filter(static fn (?string $assignmentId): bool => $assignmentId !== null)
            ->unique()
            ->count();
        $revenueMinor = $purchaseRevenue - $refundRevenue;

        return [
            'variant_id' => (string) $variant->getKey(),
            'code' => (string) $variant->code,
            'name' => (string) $variant->name,
            'position' => (int) $variant->position,
            'assignments' => $assignmentCount,
            'checkout_starts' => $checkoutStarts,
            'purchases' => $purchaseEvents->count(),
            'refunds' => $refundEvents->count(),
            'revenue_minor' => $revenueMinor,
            'conversion_rate' => $assignmentCount > 0 ? round($convertingAssignments / $assignmentCount, 4) : 0.0,
            'revenue_per_visitor' => $assignmentCount > 0 ? round($revenueMinor / $assignmentCount, 2) : 0.0,
        ];
    }

    /**
     * @return array{experiment_id: string, variant_id: string, assignment_id?: string}|null
     */
    private function resolveContextForExperiment(SignalEvent $event, Experiment $experiment): ?array
    {
        $properties = is_array($event->properties) ? $event->properties : [];
        $contexts = data_get($properties, 'experiment_contexts');

        if (is_array($contexts)) {
            foreach ($contexts as $context) {
                $normalized = $this->normalizeContext($context);

                if ($normalized !== null && $normalized['experiment_id'] === (string) $experiment->getKey()) {
                    return $normalized;
                }
            }
        }

        $singleContext = $this->normalizeContext($properties);

        return $singleContext !== null && $singleContext['experiment_id'] === (string) $experiment->getKey()
            ? $singleContext
            : null;
    }

    /**
     * @return array{experiment_id: string, variant_id: string, assignment_id?: string}|null
     */
    private function normalizeContext(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $experimentId = data_get($context, 'experiment_id');
        $variantId = data_get($context, 'variant_id');

        if (! is_scalar($experimentId) || ! is_scalar($variantId)) {
            return null;
        }

        $normalized = [
            'experiment_id' => (string) $experimentId,
            'variant_id' => (string) $variantId,
        ];
        $assignmentId = data_get($context, 'assignment_id');

        if (is_scalar($assignmentId) && (string) $assignmentId !== '') {
            $normalized['assignment_id'] = (string) $assignmentId;
        }

        return $normalized;
    }

    /** @param array<int, array<string, float|int|string|null>> $variantMetrics */
    private function hasWinnerMetricData(array $variantMetrics, string $winnerMetric): bool
    {
        return collect($variantMetrics)->contains(fn (array $metrics): bool => (float) ($metrics[$winnerMetric] ?? 0) > 0);
    }

    private function eventMatchesCurrency(SignalEvent $event, string $currency): bool
    {
        return is_string($event->currency)
            && $event->currency !== ''
            && mb_strtoupper($event->currency) === mb_strtoupper($currency);
    }
}
