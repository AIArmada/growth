<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Enums\ResolveStrategy;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Support\Context\ExperimentResolver;
use AIArmada\Growth\Support\MetricsCalculator;
use AIArmada\Growth\Support\Queries\AssignmentQuery;
use AIArmada\Growth\Support\Queries\SignalEventQuery;
use AIArmada\Growth\Support\Queries\VariantQuery;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;

final class AggregateExperimentMetrics
{
    public function __construct(
        private readonly ExperimentResolver $experimentResolver,
        private readonly VariantQuery $variantQuery,
        private readonly AssignmentQuery $assignmentQuery,
        private readonly SignalEventQuery $signalEventQuery,
        private readonly MetricsCalculator $metricsCalculator,
    ) {}

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
        $variants = $this->variantQuery->forExperiment($experiment)->get()->sortBy('position')->values();
        $assignments = $this->assignmentQuery->forExperiment($experiment)->get()->groupBy('variant_id');
        $events = $this->signalEventQuery->forExperiment($experiment)
            ->where('tracked_property_id', $experiment->tracked_property_id)
            ->whereIn('event_name', [$checkoutStartedEventName, $purchaseEventName, $refundEventName])
            ->orderBy('occurred_at')
            ->get(['id', 'tracked_property_id', 'occurred_at', 'event_name', 'event_category', 'revenue_minor', 'currency', 'properties']);
        $calculated = $this->metricsCalculator->calculate(
            experiment: $experiment,
            variants: $variants,
            assignments: $assignments,
            events: $events,
            experimentCurrency: $experimentCurrency,
            checkoutStartedEventName: $checkoutStartedEventName,
            purchaseEventName: $purchaseEventName,
            refundEventName: $refundEventName,
        );
        $results = [
            'experiment_id' => (string) $experiment->getKey(),
            'currency' => $experimentCurrency,
            'winner_metric' => (string) $experiment->winner_metric,
            ...$calculated,
        ];

        $this->storeCachedResults((string) $experiment->getKey(), $results);

        return $results;
    }

    private function resolveExperimentForCurrentScope(Experiment $experiment): Experiment
    {
        $resolvedExperiment = $this->experimentResolver->resolve(
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
            $trackedProperty = TrackedProperty::query()->whereKey($trackedPropertyId)->first();

            return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
        }

        $owner = Experiment::ownerScopeConfig()->enabled
            ? OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id)
            : OwnerContext::resolve();

        if (! Experiment::ownerScopeConfig()->enabled) {
            OwnerContext::assertResolvedOrExplicitGlobal(
                $owner,
                'Tracked property is not accessible in the current owner scope.',
            );
        }

        $trackedProperty = app(ScopeSignalQueryToOwner::class)
            ->handle(TrackedProperty::query(), $owner, TrackedProperty::ownerScopeConfig()->includeGlobal)
            ->whereKey($trackedPropertyId)
            ->first();

        return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
    }

    private function experimentCurrency(Experiment $experiment): string
    {
        return (string) ($experiment->trackedProperty?->currency ?? config('signals.defaults.currency', 'MYR'));
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
        $cache = is_array($cache) ? $cache : [];
        $cache[$experimentId] = $results;
        request()->attributes->set('growth.aggregate_metrics', $cache);
    }
}
