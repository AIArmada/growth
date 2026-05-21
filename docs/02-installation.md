---
title: Installation
---

# Installation

## Install the package

```bash
composer require aiarmada/growth
```

The package auto-registers its service provider through Laravel package discovery.

## Run migrations

Growth migrations are auto-discovered. Run your application's migrations as usual:

```bash
php artisan migrate
```

## Publish the configuration file

```bash
php artisan vendor:publish --tag=growth-config
```

This creates `config/growth.php`.

## Enable optional presentation helpers

The HTTP middleware and Blade directives are **disabled by default**. Turn them on only if you want route-level assignment resolution and template branching helpers:

```php
'features' => [
    'experiment_middleware' => [
        'enabled' => true,
    ],
    'blade_directives' => [
        'enabled' => true,
    ],
],
```

If you enable the middleware, review the request subject settings under `growth.http.experiment_middleware` as well. The defaults assume:

- anonymous visitors are identified by a cookie named `visitor_id`
- session matching uses Laravel's current session id
- authenticated users are matched to `SignalIdentity` by `auth_user_type + auth_user_id`, then by `external_id`

The global `experiment()` helper, `Growth` facade, and Livewire concern do not require extra setup beyond the package booting, but they only return data after an experiment context has been placed on the current request.

## Owner scoping setup

Growth models are owner-scoped by default. Before creating or querying experiments in an owner-aware application, make sure you have a current owner context.

Typical options:

- bind `OwnerResolverInterface` in your application so HTTP requests resolve an owner automatically
- use `OwnerContext::withOwner($owner, fn () => ...)` in jobs, commands, and explicit service flows
- use `OwnerContext::withOwner(null, fn () => ...)` for intentional global records

Example owner-scoped creation:

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Growth\Models\Experiment;
use AIArmada\Signals\Models\TrackedProperty;

$experiment = OwnerContext::withOwner($store, function () use ($trackedProperty): Experiment {
    return Experiment::query()->create([
        'tracked_property_id' => $trackedProperty->getKey(),
        'name' => 'Homepage Revenue Test',
        'slug' => 'homepage-revenue-test',
        'module_type' => 'sales_page_test',
        'status' => 'active',
    ]);
});
```

## Optional Filament admin package

If you want an admin UI for experiments, variants, results, and dashboard widgets:

```bash
composer require aiarmada/filament-growth
```

Then register the plugin with your Filament panel:

```php
use AIArmada\FilamentGrowth\FilamentGrowthPlugin;
use Filament\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(FilamentGrowthPlugin::make());
}
```

For package-specific navigation, feature flags, and page/widget behavior, see the [`filament-growth` docs](../../filament-growth/docs/01-overview.md).

## Verify the installation

Create a tracked property, experiment, and variant, then resolve an assignment using the examples in [usage](./04-usage.md).

If you plan to use HTTP presentation helpers, also add one route with `growth.experiment:{slug}` and confirm `experiment()` returns the current variant context.