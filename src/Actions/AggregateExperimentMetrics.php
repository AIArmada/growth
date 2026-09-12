<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\Filament\OwnerUiScope;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Enums\ResolveStrategy;
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Growth\Support\Context\ExperimentResolver;
use AIArmada\Growth\Support\ExperimentMetricBatchRow;
use AIArmada\Growth\Support\MetricsCalculator;
use AIArmada\Growth\Support\Queries\AssignmentQuery;
use AIArmada\Growth\Support\Queries\SignalEventQuery;
use AIArmada\Growth\Support\Queries\VariantQuery;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

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

    /**
     * Aggregate dashboard experiments with one batched child query.
     *
     * Variants, assignments, and signal events share one UNION query. The
     * dashboard owns the experiment list query, so this keeps ten experiment
     * cards at three SQL queries including tracked-property eager loading.
     *
     * @param  EloquentCollection<int, Experiment>  $experiments
     * @return array{results: array<string, array<string, mixed>>, variant_count: int, assignment_count: int}
     */
    public function handleMany(EloquentCollection $experiments): array
    {
        $experiments->loadMissing('trackedProperty');

        $checkoutStartedEventName = (string) config('growth.integrations.signals.checkout_started_event_name', 'checkout.started');
        $refundEventName = (string) config('growth.integrations.signals.refund_event_name', 'order.refunded');
        $eventNames = [$checkoutStartedEventName, $refundEventName];

        foreach ($experiments as $experiment) {
            $eventNames[] = (string) ($experiment->goal_event_name ?: config('growth.integrations.signals.purchase_event_name', 'order.paid'));
        }

        $eventNames = array_values(array_unique($eventNames));
        $rows = $this->loadBatchRows($experiments, $eventNames);

        $variants = collect();
        $assignments = collect();
        $events = collect();

        foreach ($rows as $row) {
            if ($row->recordType === 'variant') {
                $variants->push((new Variant)->newFromBuilder([
                    'id' => $row->recordId,
                    'experiment_id' => $row->experimentId,
                    'code' => $row->code,
                    'name' => $row->name,
                    'position' => $row->position,
                ]));

                continue;
            }

            if ($row->recordType === 'assignment') {
                $assignments->push((new Assignment)->newFromBuilder([
                    'id' => $row->recordId,
                    'experiment_id' => $row->experimentId,
                    'variant_id' => $row->variantId,
                    'subject_key' => $row->subjectKey,
                    'assigned_at' => $row->assignedAt,
                ]));

                continue;
            }

            $events->push((new SignalEvent)->newFromBuilder([
                'id' => $row->recordId,
                'tracked_property_id' => $row->trackedPropertyId,
                'occurred_at' => $row->occurredAt,
                'event_name' => $row->eventName,
                'event_category' => $row->eventCategory,
                'revenue_minor' => $row->revenueMinor,
                'currency' => $row->currency,
                'properties' => $row->properties,
            ]));
        }

        /** @var Collection<string, Collection<int, Variant>> $variantsByExperiment */
        $variantsByExperiment = $variants->groupBy(
            static fn (Variant $variant): string => (string) $variant->experiment_id,
        );
        /** @var Collection<string, Collection<string, Collection<int, Assignment>>> $assignmentsByExperiment */
        $assignmentsByExperiment = $assignments
            ->groupBy(static fn (Assignment $assignment): string => (string) $assignment->experiment_id)
            ->map(static fn (Collection $experimentAssignments): Collection => $experimentAssignments->groupBy(
                static fn (Assignment $assignment): string => (string) $assignment->variant_id,
            ));
        /** @var Collection<string, Collection<int, SignalEvent>> $eventsByTrackedProperty */
        $eventsByTrackedProperty = $events->groupBy(
            static fn (SignalEvent $event): string => (string) $event->tracked_property_id,
        );

        /** @var array<string, array<string, mixed>> $results */
        $results = [];

        foreach ($experiments as $experiment) {
            $experimentId = (string) $experiment->getKey();
            $cachedResults = $this->cachedResults($experimentId);

            if (is_array($cachedResults)) {
                $results[$experimentId] = $cachedResults;

                continue;
            }

            $trackedProperty = $experiment->trackedProperty;

            if (! $trackedProperty instanceof TrackedProperty) {
                continue;
            }

            $purchaseEventName = (string) ($experiment->goal_event_name ?: config('growth.integrations.signals.purchase_event_name', 'order.paid'));
            /** @var Collection<int, Variant> $experimentVariants */
            $experimentVariants = $variantsByExperiment->get($experimentId, collect())->sortBy('position')->values();
            /** @var Collection<string, Collection<int, Assignment>> $experimentAssignments */
            $experimentAssignments = $assignmentsByExperiment->get($experimentId, collect());
            /** @var Collection<int, SignalEvent> $experimentEvents */
            $experimentEvents = $eventsByTrackedProperty->get((string) $experiment->tracked_property_id, collect());

            $calculated = $this->metricsCalculator->calculate(
                experiment: $experiment,
                variants: $experimentVariants,
                assignments: $experimentAssignments,
                events: $experimentEvents,
                experimentCurrency: (string) ($trackedProperty->currency ?? config('signals.defaults.currency', 'MYR')),
                checkoutStartedEventName: $checkoutStartedEventName,
                purchaseEventName: $purchaseEventName,
                refundEventName: $refundEventName,
            );
            $results[$experimentId] = [
                'experiment_id' => $experimentId,
                'currency' => (string) ($trackedProperty->currency ?? config('signals.defaults.currency', 'MYR')),
                'winner_metric' => (string) $experiment->winner_metric,
                ...$calculated,
            ];

            $this->storeCachedResults($experimentId, $results[$experimentId]);
        }

        return [
            'results' => $results,
            'variant_count' => $variants->count(),
            'assignment_count' => $assignments->count(),
        ];
    }

    /**
     * @param  EloquentCollection<int, Experiment>  $experiments
     * @param  list<string>  $eventNames
     * @return Collection<int, ExperimentMetricBatchRow>
     */
    private function loadBatchRows(EloquentCollection $experiments, array $eventNames): Collection
    {
        $variantTable = (new Variant)->getTable();
        $assignmentTable = (new Assignment)->getTable();
        $experimentIds = $experiments->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $signalsJsonColumnType = commerce_json_column_type('signals', 'jsonb');
        $variantQuery = OwnerUiScope::apply(Variant::query(), includeGlobal: false)
            ->whereIn($variantTable . '.experiment_id', $experimentIds)
            ->select([
                DB::raw("'variant' AS record_type"),
                $variantTable . '.id AS record_id',
                $variantTable . '.experiment_id',
                DB::raw('CAST(NULL AS uuid) AS tracked_property_id'),
                DB::raw('NULL AS variant_id'),
                DB::raw('NULL AS subject_key'),
                DB::raw('NULL AS assigned_at'),
                DB::raw('CAST(NULL AS timestamptz) AS occurred_at'),
                DB::raw('NULL AS event_name'),
                DB::raw('NULL AS event_category'),
                DB::raw('CAST(NULL AS bigint) AS revenue_minor'),
                DB::raw('NULL AS currency'),
                DB::raw("CAST(NULL AS {$signalsJsonColumnType}) AS properties"),
                $variantTable . '.code',
                $variantTable . '.name',
                $variantTable . '.position',
            ]);
        $assignmentQuery = OwnerUiScope::apply(Assignment::query(), includeGlobal: false)
            ->whereIn($assignmentTable . '.experiment_id', $experimentIds)
            ->select([
                DB::raw("'assignment' AS record_type"),
                $assignmentTable . '.id AS record_id',
                $assignmentTable . '.experiment_id',
                DB::raw('CAST(NULL AS uuid) AS tracked_property_id'),
                $assignmentTable . '.variant_id',
                $assignmentTable . '.subject_key',
                $assignmentTable . '.assigned_at',
                DB::raw('CAST(NULL AS timestamptz) AS occurred_at'),
                DB::raw('NULL AS event_name'),
                DB::raw('NULL AS event_category'),
                DB::raw('CAST(NULL AS bigint) AS revenue_minor'),
                DB::raw('NULL AS currency'),
                DB::raw("CAST(NULL AS {$signalsJsonColumnType}) AS properties"),
                DB::raw('NULL AS code'),
                DB::raw('NULL AS name'),
                DB::raw('NULL AS position'),
            ]);
        $query = $variantQuery->toBase()->unionAll($assignmentQuery->toBase());

        $mapRow = static fn (stdClass $row): ExperimentMetricBatchRow => new ExperimentMetricBatchRow(
            recordType: (string) $row->record_type,
            recordId: (string) $row->record_id,
            experimentId: $row->experiment_id === null ? null : (string) $row->experiment_id,
            trackedPropertyId: $row->tracked_property_id === null ? null : (string) $row->tracked_property_id,
            variantId: $row->variant_id === null ? null : (string) $row->variant_id,
            subjectKey: $row->subject_key === null ? null : (string) $row->subject_key,
            assignedAt: $row->assigned_at,
            occurredAt: $row->occurred_at,
            eventName: $row->event_name === null ? null : (string) $row->event_name,
            eventCategory: $row->event_category === null ? null : (string) $row->event_category,
            revenueMinor: $row->revenue_minor === null ? null : (int) $row->revenue_minor,
            currency: $row->currency === null ? null : (string) $row->currency,
            properties: $row->properties,
            code: $row->code === null ? null : (string) $row->code,
            name: $row->name === null ? null : (string) $row->name,
            position: $row->position === null ? null : (int) $row->position,
        );

        if ($experiments->isEmpty()) {
            return $query->get()->map($mapRow);
        }

        $eventTable = (new SignalEvent)->getTable();
        $trackedPropertyIds = $experiments->pluck('tracked_property_id')->filter()->unique()->values()->all();
        $eventQuery = OwnerUiScope::apply(SignalEvent::query(), includeGlobal: false)
            ->whereIn($eventTable . '.tracked_property_id', $trackedPropertyIds)
            ->whereIn($eventTable . '.event_name', $eventNames)
            ->select([
                DB::raw("'event' AS record_type"),
                $eventTable . '.id AS record_id',
                DB::raw('NULL AS experiment_id'),
                $eventTable . '.tracked_property_id',
                DB::raw('NULL AS variant_id'),
                DB::raw('NULL AS subject_key'),
                DB::raw('NULL AS assigned_at'),
                $eventTable . '.occurred_at',
                $eventTable . '.event_name',
                $eventTable . '.event_category',
                $eventTable . '.revenue_minor',
                $eventTable . '.currency',
                $eventTable . '.properties',
                DB::raw('NULL AS code'),
                DB::raw('NULL AS name'),
                DB::raw('NULL AS position'),
            ]);

        $query->unionAll($eventQuery->toBase());

        return $query->get()->map($mapRow);
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
