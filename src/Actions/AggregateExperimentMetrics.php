<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Enums\ResolveStrategy;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Support\Context\ExperimentResolver;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class AggregateExperimentMetrics
{
    /**
     * @return array{
     *     experiment_id: string,
     *     currency: string,
     *     winner_metric: string,
     *     winner_variant_id: string|null,
     *     totals: array{assignments: int, checkout_starts: int, purchases: int, refunds: int, revenue_minor: int},
     *     variants: array<int, array<string, float|int|string|null>>
     * }
     */
    public function handle(Experiment $experiment): array
    {
        $experiment = $this->resolveExperimentForCurrentScope($experiment);
        $cachedResults = $this->cachedResults((string) $experiment->getKey());

        if (is_array($cachedResults)) {
            return $cachedResults;
        }

        $checkoutStartedEventName = (string) config('growth.integrations.signals.checkout_started_event_name', 'checkout.started');
        $purchaseEventName = (string) ($experiment->goal_event_name ?: config('growth.integrations.signals.purchase_event_name', 'order.paid'));
        $refundEventName = (string) config('growth.integrations.signals.refund_event_name', 'order.refunded');
        $experimentCurrency = $this->experimentCurrency($experiment);

        $variants = $this->variantQuery($experiment)
            ->where('experiment_id', $experiment->getKey())
            ->get()
            ->sortBy('position')
            ->values();

        $assignments = $this->assignmentQuery($experiment)
            ->where('experiment_id', $experiment->getKey())
            ->get()
            ->groupBy('variant_id');

        $events = $this->signalEventQuery($experiment)
            ->where('tracked_property_id', $experiment->tracked_property_id)
            ->whereIn('event_name', [$checkoutStartedEventName, $purchaseEventName, $refundEventName])
            ->orderBy('occurred_at')
            ->get(['id', 'tracked_property_id', 'occurred_at', 'event_name', 'event_category', 'revenue_minor', 'currency', 'properties'])
            ->map(function (SignalEvent $event) use ($experiment): ?array {
                $context = $this->resolveContextForExperiment($event, $experiment);

                if ($context === null) {
                    return null;
                }

                return [
                    'event' => $event,
                    'context' => $context,
                ];
            })
            ->filter()
            ->values()
            ->groupBy(fn (array $payload): string => (string) Arr::get($payload, 'context.variant_id', ''));

        $winnerMetric = (string) $experiment->winner_metric;
        $variantMetrics = $variants->map(
            fn (Variant $variant): array => $this->variantMetrics($variant, $assignments, $events, $experiment, $experimentCurrency)
        )->all();

        $totals = [
            'assignments' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['assignments'], $variantMetrics)),
            'checkout_starts' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['checkout_starts'], $variantMetrics)),
            'purchases' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['purchases'], $variantMetrics)),
            'refunds' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['refunds'], $variantMetrics)),
            'revenue_minor' => array_sum(array_map(static fn (array $metrics): int => (int) $metrics['revenue_minor'], $variantMetrics)),
        ];

        $winnerVariantId = null;

        if ($totals['assignments'] > 0 && $this->hasWinnerMetricData($variantMetrics, $winnerMetric)) {
            $winningMetrics = collect($variantMetrics)
                ->sortByDesc(fn (array $metrics): array => [
                    (float) ($metrics[$winnerMetric] ?? 0),
                    (int) $metrics['assignments'],
                    -1 * (int) $metrics['position'],
                ])
                ->first();

            $winnerVariantId = is_array($winningMetrics) && is_string($winningMetrics['variant_id'] ?? null)
                ? $winningMetrics['variant_id']
                : null;
        }

        $results = [
            'experiment_id' => (string) $experiment->getKey(),
            'currency' => $experimentCurrency,
            'winner_metric' => $winnerMetric,
            'winner_variant_id' => is_string($winnerVariantId) ? $winnerVariantId : null,
            'totals' => $totals,
            'variants' => $variantMetrics,
        ];

        $this->storeCachedResults((string) $experiment->getKey(), $results);

        return $results;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function variantMetrics(
        Variant $variant,
        Collection $assignments,
        Collection $events,
        Experiment $experiment,
        string $experimentCurrency,
    ): array {
        /** @var Collection<int, Assignment> $variantAssignments */
        $variantAssignments = $assignments->get((string) $variant->getKey(), collect());
        /** @var Collection<int, array{event: SignalEvent, context: array<string, string>}> $variantEvents */
        $variantEvents = $events->get((string) $variant->getKey(), collect());

        $checkoutStartedEventName = (string) config('growth.integrations.signals.checkout_started_event_name', 'checkout.started');
        $purchaseEventName = (string) ($experiment->goal_event_name ?: config('growth.integrations.signals.purchase_event_name', 'order.paid'));
        $refundEventName = (string) config('growth.integrations.signals.refund_event_name', 'order.refunded');

        $checkoutStarts = $variantEvents
            ->filter(fn (array $payload): bool => $payload['event']->event_name === $checkoutStartedEventName)
            ->count();
        /** @var Collection<int, array{event: SignalEvent, context: array<string, string>}> $purchaseEvents */
        $purchaseEvents = $variantEvents
            ->filter(fn (array $payload): bool => $payload['event']->event_name === $purchaseEventName)
            ->values();
        /** @var Collection<int, array{event: SignalEvent, context: array<string, string>}> $matchingCurrencyPurchaseEvents */
        $matchingCurrencyPurchaseEvents = $purchaseEvents
            ->filter(fn (array $payload): bool => $this->eventMatchesExperimentCurrency($payload['event'], $experimentCurrency))
            ->values();
        $purchases = $purchaseEvents->count();
        /** @var Collection<int, array{event: SignalEvent, context: array<string, string>}> $refundEvents */
        $refundEvents = $variantEvents
            ->filter(fn (array $payload): bool => $payload['event']->event_name === $refundEventName)
            ->values();
        /** @var Collection<int, array{event: SignalEvent, context: array<string, string>}> $matchingCurrencyRefundEvents */
        $matchingCurrencyRefundEvents = $refundEvents
            ->filter(fn (array $payload): bool => $this->eventMatchesExperimentCurrency($payload['event'], $experimentCurrency))
            ->values();
        $refunds = $refundEvents->count();
        $purchaseRevenue = (int) $matchingCurrencyPurchaseEvents
            ->sum(fn (array $payload): int => (int) $payload['event']->revenue_minor);
        $refundRevenue = (int) $matchingCurrencyRefundEvents
            ->sum(fn (array $payload): int => (int) $payload['event']->revenue_minor);
        $revenueMinor = $purchaseRevenue - $refundRevenue;
        $assignmentCount = $variantAssignments->count();
        $convertingAssignments = $purchaseEvents
            ->map(function (array $payload): ?string {
                $assignmentId = Arr::get($payload, 'context.assignment_id');

                if (! is_scalar($assignmentId) || (string) $assignmentId === '') {
                    return null;
                }

                return (string) $assignmentId;
            })
            ->filter(static fn (?string $assignmentId): bool => $assignmentId !== null)
            ->unique()
            ->count();

        $conversionRate = $assignmentCount > 0 ? round($convertingAssignments / $assignmentCount, 4) : 0.0;
        $revenuePerVisitor = $assignmentCount > 0 ? round($revenueMinor / $assignmentCount, 2) : 0.0;

        return [
            'variant_id' => (string) $variant->getKey(),
            'code' => (string) $variant->code,
            'name' => (string) $variant->name,
            'position' => (int) $variant->position,
            'assignments' => $assignmentCount,
            'checkout_starts' => $checkoutStarts,
            'purchases' => $purchases,
            'refunds' => $refunds,
            'revenue_minor' => $revenueMinor,
            'conversion_rate' => $conversionRate,
            'revenue_per_visitor' => $revenuePerVisitor,
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

                if ($normalized === null) {
                    continue;
                }

                if ($normalized['experiment_id'] === (string) $experiment->getKey()) {
                    return $normalized;
                }
            }
        }

        $singleContext = $this->normalizeContext($properties);

        if ($singleContext === null || $singleContext['experiment_id'] !== (string) $experiment->getKey()) {
            return null;
        }

        return $singleContext;
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

        $normalizedContext = [
            'experiment_id' => (string) $experimentId,
            'variant_id' => (string) $variantId,
        ];

        $assignmentId = data_get($context, 'assignment_id');

        if (is_scalar($assignmentId) && (string) $assignmentId !== '') {
            $normalizedContext['assignment_id'] = (string) $assignmentId;
        }

        return $normalizedContext;
    }

    /**
     * @param  array<int, array<string, float|int|string|null>>  $variantMetrics
     */
    private function hasWinnerMetricData(array $variantMetrics, string $winnerMetric): bool
    {
        return collect($variantMetrics)
            ->contains(fn (array $metrics): bool => (float) ($metrics[$winnerMetric] ?? 0) > 0);
    }

    private function resolveExperimentForCurrentScope(Experiment $experiment): Experiment
    {
        $resolvedExperiment = app(ExperimentResolver::class)
            ->resolve(
                (string) $experiment->getKey(),
                ResolveStrategy::Readable,
                'Growth experiment is not accessible in the current owner scope.',
            );

        $trackedProperty = $this->resolveTrackedPropertyForExperiment($resolvedExperiment);

        if (! $trackedProperty instanceof TrackedProperty) {
            throw new AuthorizationException('Tracked property is not accessible in the current owner scope.');
        }

        $resolvedExperiment->setRelation('trackedProperty', $trackedProperty);

        return $resolvedExperiment;
    }

    private function resolveTrackedPropertyForExperiment(Experiment $experiment): ?TrackedProperty
    {
        $trackedPropertyId = (string) $experiment->tracked_property_id;

        if (! Experiment::ownerScopeConfig()->enabled && ! TrackedProperty::ownerScopeConfig()->enabled) {
            $trackedProperty = TrackedProperty::query()
                ->whereKey($trackedPropertyId)
                ->first();

            return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
        }

        $owner = Experiment::ownerScopeConfig()->enabled
            ? $this->signalOwnerForExperiment($experiment)
            : OwnerContext::resolve();

        if (! Experiment::ownerScopeConfig()->enabled) {
            OwnerContext::assertResolvedOrExplicitGlobal(
                $owner,
                'Tracked property is not accessible in the current owner scope.',
            );
        }

        $trackedProperty = app(ScopeSignalQueryToOwner::class)
            ->handle(
                TrackedProperty::query(),
                $owner,
                TrackedProperty::ownerScopeConfig()->includeGlobal,
            )
            ->whereKey($trackedPropertyId)
            ->first();

        return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
    }

    /**
     * @return Builder<Variant>
     */
    private function variantQuery(Experiment $experiment): Builder
    {
        if (! Variant::ownerScopeConfig()->enabled) {
            return Variant::query();
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        if ($owner === null) {
            return Variant::query()->globalOnly();
        }

        return Variant::query()->forOwner($owner, includeGlobal: false);
    }

    /**
     * @return Builder<Assignment>
     */
    private function assignmentQuery(Experiment $experiment): Builder
    {
        if (! Assignment::ownerScopeConfig()->enabled) {
            return Assignment::query();
        }

        $owner = OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);

        if ($owner === null) {
            return Assignment::query()->globalOnly();
        }

        return Assignment::query()->forOwner($owner, includeGlobal: false);
    }

    /**
     * @return Builder<SignalEvent>
     */
    private function signalEventQuery(Experiment $experiment): Builder
    {
        if (! SignalEvent::ownerScopeConfig()->enabled && ! Experiment::ownerScopeConfig()->enabled) {
            return SignalEvent::query();
        }

        $trackedProperty = $experiment->trackedProperty;

        if (! $trackedProperty instanceof TrackedProperty) {
            return SignalEvent::query()->whereRaw('1 = 0');
        }

        $owner = OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);

        return app(ScopeSignalQueryToOwner::class)->handle(SignalEvent::query(), $owner);
    }

    private function signalOwnerForExperiment(Experiment $experiment): ?Model
    {
        return OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);
    }

    private function experimentCurrency(Experiment $experiment): string
    {
        return (string) ($experiment->trackedProperty?->currency ?? config('signals.defaults.currency', 'MYR'));
    }

    private function eventMatchesExperimentCurrency(SignalEvent $event, string $experimentCurrency): bool
    {
        return is_string($event->currency)
            && $event->currency !== ''
            && mb_strtoupper($event->currency) === mb_strtoupper($experimentCurrency);
    }

    /**
     * @return array{experiment_id: string, currency: string, winner_metric: string, winner_variant_id: string|null, totals: array{assignments: int, checkout_starts: int, purchases: int, refunds: int, revenue_minor: int}, variants: array<int, array<string, float|int|string|null>>}|null
     */
    private function cachedResults(string $experimentId): ?array
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        $cache = request()->attributes->get('growth.aggregate_metrics', []);

        if (! is_array($cache)) {
            return null;
        }

        $cachedResults = $cache[$experimentId] ?? null;

        return is_array($cachedResults) ? $cachedResults : null;
    }

    /**
     * @param  array{experiment_id: string, currency: string, winner_metric: string, winner_variant_id: string|null, totals: array{assignments: int, checkout_starts: int, purchases: int, refunds: int, revenue_minor: int}, variants: array<int, array<string, float|int|string|null>>}  $results
     */
    private function storeCachedResults(string $experimentId, array $results): void
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return;
        }

        $cache = request()->attributes->get('growth.aggregate_metrics', []);

        if (! is_array($cache)) {
            $cache = [];
        }

        $cache[$experimentId] = $results;

        request()->attributes->set('growth.aggregate_metrics', $cache);
    }
}
