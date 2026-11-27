---
name: growth-development
description: Develop growth integrations using owner-safe Growth operations and Laravel Dune package boundaries.
---

# Growth

Growth owns the experiment aggregate (experiments, variants and assignments). Keep all durable writes in named Actions, including maintenance and deletion. Read-only builders belong in Queries; immutable scalar data belongs in Data. HTTP and Livewire adapters live in EntryPoints. Owner scoping must match Signals; never remove owner validation from assignment or property resolution.

Use the `growth.experiment` middleware alias to resolve an experiment. Custom request subject resolvers implement `AIArmada\Growth\EntryPoints\Http\RequestExperimentSubjectResolver`. The context facade and helpers expose the current request's assignment. Use `AIArmada\Growth\Actions\ResolveExperimentAssignment` for explicit assignments and `AIArmada\Growth\Actions\AggregateExperimentMetrics` for analytics. For administrative writes use `AIArmada\Growth\Actions\SaveExperiment`, `AIArmada\Growth\Actions\SaveVariant`, and `AIArmada\Growth\Actions\UpdateGrowthSettings`. Enforce the application's authorization policy at its entry point; domain actions independently constrain writable owners. Authenticated experiment subjects require a typed `SignalIdentity` (`auth_user_type` + `auth_user_id`); there is no `external_id` fallback. `UpdateGrowthSettings` is a global write and requires explicit global owner context when Growth or Signals ownership is enabled. Route status changes through the model `transitionTo()` rules. Never add compatibility aliases for moved classes.

## Verification

From the Commerce monorepo root, run `composer --working-dir=packages/growth arch:check`, the affected Pest tests, PHPStan and Pint. Update callers directly when public APIs move; do not introduce compatibility wrappers.
