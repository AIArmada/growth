---
title: Overview
---

# Growth

`aiarmada/growth` adds revenue experimentation primitives to the Commerce ecosystem. It lets you define experiments, split traffic across variants, persist sticky assignments, enrich downstream Signals events with experiment context, and aggregate experiment results back into winner-ready metrics.

## What the package manages

| Model | Purpose |
| --- | --- |
| `Experiment` | The experiment definition, outcome goal, and winner metric |
| `Variant` | A traffic bucket within an experiment |
| `Assignment` | A sticky subject-to-variant allocation |

Every experiment belongs to a Signals `TrackedProperty`, which becomes the analytics source for attribution and reporting.

## Supported experiment modules

The package ships with four built-in module presets:

- `ab_test`
- `sales_page_test`
- `funnel_test`
- `pricing_test`

Presets provide sensible defaults for:

- goal event name
- goal event category
- winner metric
- module-specific settings

## Core workflows

The main runtime flows are:

1. Create an `Experiment` for a `TrackedProperty`
2. Add one or more `Variant` records
3. Resolve an `Assignment` for a buyer/session/anonymous subject
4. Project experiment context into Signals event properties
5. Aggregate attributed signals into variant metrics and winner summaries

## Package features

- Owner-scoped experiments, variants, and assignments by default
- Sticky assignment resolution for identities, sessions, and anonymous visitors
- Optional HTTP middleware that resolves an active experiment by explicit slug and stores request-scoped variant context
- Global `experiment()` helper and `Growth` facade for the current request context
- Optional Blade `@variant / @elsevariant / @endvariant` branching helpers
- Lightweight Livewire-friendly `InteractsWithExperimentContext` concern
- Oversized anonymous identifiers are hashed into deterministic subject keys before persistence
- Signals event enrichment through `ProjectExperimentContextIntoSignalProperties`
- Aggregate metrics through `AggregateExperimentMetrics`
- Preset-aware defaults during experiment creation
- Explicit experiment slugs with automatic `Str::slug()` fallback when the slug is left blank on create
- Service-container binding for `growth.signal_event_property_enricher`
- Optional admin UI via [`aiarmada/filament-growth`](../../filament-growth/docs/01-overview.md)

## Related packages

- [`aiarmada/signals`](../../signals/docs/01-overview.md) for tracked properties, sessions, identities, and events
- [`aiarmada/commerce-support`](../../commerce-support/docs/01-overview.md) for owner scoping, owner guards, and shared utilities
- [`aiarmada/filament-growth`](../../filament-growth/docs/01-overview.md) for Filament resources, results pages, and widgets

## Next steps

- Start with [installation](./02-installation.md)
- Review [configuration](./03-configuration.md)
- Walk through [usage](./04-usage.md)
- Add the admin UI with [`filament-growth`](../../filament-growth/docs/01-overview.md)