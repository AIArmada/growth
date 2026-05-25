---
title: Usage
---

# Usage

## Create an experiment

Experiments are tied to a Signals `TrackedProperty` and should be created inside an explicit owner context when owner scoping is enabled.

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;
use AIArmada\Signals\Models\TrackedProperty;

$experiment = OwnerContext::withOwner($store, function () use ($trackedProperty): Experiment {
    /** @var Experiment $experiment */
    $experiment = Experiment::query()->create([
        'tracked_property_id' => $trackedProperty->getKey(),
        'name' => 'Pricing Page Test',
        'slug' => 'pricing-page-test',
        'module_type' => 'pricing_test',
        'status' => 'active',
    ]);

    Variant::query()->create([
        'experiment_id' => $experiment->getKey(),
        'code' => 'A',
        'name' => 'Control',
        'traffic_percentage' => 50,
        'position' => 1,
        'is_control' => true,
        'is_active' => true,
    ]);

    Variant::query()->create([
        'experiment_id' => $experiment->getKey(),
        'code' => 'B',
        'name' => 'Higher Anchoring',
        'traffic_percentage' => 50,
        'position' => 2,
        'is_control' => false,
        'is_active' => true,
    ]);

    return $experiment->fresh(['variants']) ?? $experiment;
});
```

If `slug` is blank during creation, Growth fills it from `name` using `Str::slug()`. The slug remains an explicit route-facing identifier, so middleware usage should always reference the stored slug value directly.

## Use module presets

Resolve preset defaults when you need to seed UI state or custom creation flows:

```php
use AIArmada\Growth\Actions\ResolveExperimentPreset;

$preset = app(ResolveExperimentPreset::class)->handle('funnel_test');

// ['module_type' => 'funnel_test', 'goal_event_name' => 'order.paid', ...]
```

When `growth.features.preset_modules.enabled` is `true`, blank module-specific fields are also hydrated from the preset when the experiment is created.

If `module_type` is blank, Growth falls back to `growth.defaults.module_type`. If you pass a custom unsupported module value, Growth preserves that explicit module type and only applies the generic goal and winner defaults.

## Resolve a sticky assignment

Use `ResolveExperimentAssignment` to allocate a subject to a variant.

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\ResolveExperimentAssignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;

$assignment = OwnerContext::withOwner($store, function () use ($experiment, $identity, $session) {
    return app(ResolveExperimentAssignment::class)->handle(
        $experiment,
        $identity,
        $session,
    );
});
```

You can also resolve against an anonymous identifier:

```php
$assignment = OwnerContext::withOwner($store, function () use ($experiment) {
    return app(ResolveExperimentAssignment::class)->handle(
        $experiment,
        anonymousId: 'cart-123',
    );
});
```

If the anonymous identifier would make `subject_key` longer than the database column allows, Growth stores it as `anonymous:sha256:{hash}` instead.

### Important assignment rules

- the experiment must be accessible in the current owner scope
- the experiment must be `active`
- the experiment's tracked property must also be readable in the same owner scope
- any provided `SignalIdentity` or `SignalSession` must belong to the **same tracked property** as the experiment
- persisted global experiments require an explicit global owner context before assignments can be resolved
- assignments stay sticky for the same subject

## Resolve assignments through HTTP middleware

When `growth.features.experiment_middleware.enabled` is `true`, Growth can resolve the assignment for you on an incoming request and store the result on the request attributes.

Route example:

```php
use Illuminate\Support\Facades\Route;

Route::get('/pricing', function () {
    return view('pricing.show');
})->middleware('growth.experiment:pricing-page-test');
```

Important notes:

- the middleware slug is explicit: Growth does **not** infer slugs from route names, paths, or controllers
- the experiment must be `active`
- the current owner context must allow the experiment to be read
- the request must resolve at least one subject: authenticated identity, current Signals browser context, or a configured cookie/header identifier

The built-in resolver tries, in order:

1. authenticated user → `SignalIdentity.auth_user_type + auth_user_id`, then `external_id`
2. current Signals browser session id (`sig_sid`) or the configured session identifier source
3. current Signals browser visitor id (`sig_vid`) or the configured anonymous id source

If `signals.integrations.browser.enabled` is on and its middleware has bootstrapped a browser context, you usually do not need any extra request-header wiring for Growth's built-in resolver.

## Read the current experiment context

Once middleware has stored the request context, you can read it from controllers, actions, view models, or any other HTTP-layer code.

Helper example:

```php
$context = experiment();

if ($context?->isVariant('hero-b')) {
    // render challenger hero
}

$variantCode = $context?->variantCode();
$experimentSlug = $context?->slug;
$assignmentId = $context?->assignmentId();
```

Facade example:

```php
use AIArmada\Growth\Facades\Growth;

$variantCode = Growth::variantCode();
$experimentSlug = Growth::experimentSlug();
```

`experiment()` returns `null` when no experiment context has been stored on the current request.

## Branch in Blade views

When `growth.features.blade_directives.enabled` is `true`, you can branch templates by the current variant code:

```blade
@variant('hero-a')
    <x-sales.hero-a />
@elsevariant('hero-b')
    <x-sales.hero-b />
@else
    <x-sales.default-hero />
@endvariant
```

For simpler checks, you can also use the helper directly:

```blade
@if (experiment()?->isVariant('hero-a'))
    <p>Showing the control hero.</p>
@endif
```

## Use the Livewire-friendly helper concern

Growth ships a lightweight concern for Laravel Livewire components that want to read the current request context without introducing a hard Livewire dependency into the package itself.

```php
use AIArmada\Growth\Livewire\Concerns\InteractsWithExperimentContext;
use Livewire\Component;

final class PricingHero extends Component
{
    use InteractsWithExperimentContext;

    public function render()
    {
        return view('livewire.pricing-hero', [
            'variantCode' => $this->experimentVariantCode(),
            'isControl' => $this->isExperimentControl(),
        ]);
    }
}
```

The concern is a thin wrapper over `experiment()`. If no request context exists, its methods return `null`/`false`.

## Project experiment context into Signals event properties

Use `ProjectExperimentContextIntoSignalProperties` before recording checkout or order signals so downstream analytics can attribute revenue back to the assigned variant.

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\ProjectExperimentContextIntoSignalProperties;

$properties = OwnerContext::withOwner($store, function () use ($order, $trackedProperty): array {
    return app(ProjectExperimentContextIntoSignalProperties::class)->handle(
        $order,
        $trackedProperty,
        [
            'order_id' => $order->getKey(),
        ],
    );
});
```

The enricher looks for matching identities and assignments from these source fields:

- `customer_id`
- `cart_id`
- `metadata.cart_id`

If no matching assignment can be resolved, the action still replays any explicit experiment contexts already embedded on the source model. Current fallback sources include:

- `billing_data.metadata.experiment_contexts`
- `payment_data.experiment_contexts`
- `payment_data.metadata.experiment_contexts`
- `metadata.experiment_contexts`
- `metadata.payment_data.experiment_contexts`
- `metadata.billing_data.metadata.experiment_contexts`

Assignment-derived context remains the primary source when available, and explicit contexts are merged by `experiment_id` so downstream Signals events still carry a stable `experiment_contexts` payload.

The action adds keys such as:

- `experiment_id`
- `experiment_slug`
- `variant_id`
- `variant_code`
- `assignment_id`
- `module_type`
- `experiment_contexts`

The first context is also flattened back onto the top-level event properties so downstream queries can filter by `experiment_id`, `variant_id`, or `variant_code` without unpacking the array payload first.

## Aggregate experiment results

Use `AggregateExperimentMetrics` to compute revenue, conversion, and winner metrics for an experiment.

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Actions\AggregateExperimentMetrics;

$results = OwnerContext::withOwner($store, function () use ($experiment): array {
    return app(AggregateExperimentMetrics::class)->handle($experiment);
});
```

The aggregator reads Signals events from the experiment's tracked property, counts purchase and refund events by event name, and only sums revenue from events whose currency matches the experiment property's currency.

Example response shape:

```php
[
    'experiment_id' => '...',
    'currency' => 'MYR',
    'winner_metric' => 'revenue_per_visitor',
    'winner_variant_id' => '...',
    'totals' => [
        'assignments' => 128,
        'checkout_starts' => 67,
        'purchases' => 21,
        'refunds' => 1,
        'revenue_minor' => 499000,
    ],
    'variants' => [
        // per-variant metrics
    ],
]
```

If an experiment has no assignments yet, or it still lacks qualifying data for the configured winner metric, `winner_variant_id` is `null`. This lets UIs render a pending state instead of inventing a winner too early.

## Query models directly

```php
use AIArmada\Growth\Models\Assignment;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Growth\Models\Variant;

$experiments = Experiment::query()
    ->forOwner()
    ->with(['variants', 'trackedProperty'])
    ->latest()
    ->get();

$activeVariants = Variant::query()
    ->forOwner()
    ->active()
    ->orderBy('position')
    ->get();

$assignments = Assignment::query()
    ->forOwner()
    ->latest('assigned_at')
    ->get();
```

`forOwner()` requires either a resolved owner or an explicit global owner context.

## Optional Filament admin UI

If `aiarmada/filament-growth` is installed, you get:

- experiment and variant resources
- a results page
- dashboard widgets for tracked revenue and recent winners

Register the plugin on your panel and keep the Growth package as the underlying domain layer. For the Filament-specific workflow, see the [`filament-growth` docs](../../filament-growth/docs/01-overview.md).