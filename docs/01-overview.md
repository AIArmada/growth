---
title: Overview
---

# Growth

## Purpose

The `aiarmada/growth` package owns experimentation primitives for the Commerce ecosystem, including experiments, variants, sticky assignments, and Signals-based result aggregation.

## What this package owns

- Experiments, variants, and sticky subject assignments
- Experiment preset defaults and request-scoped experiment context helpers
- Signals event enrichment with experiment context and downstream metric aggregation
- Optional middleware, helpers, and Blade or Livewire conveniences for experimentation flows

## What this package does not own

- Tracked properties, identities, sessions, and raw event storage; those stay in `aiarmada/signals`
- Filament admin surfaces; those stay in `aiarmada/filament-growth`
- Product, pricing, or checkout domain logic; experiments may observe those flows but do not own them

## Related packages

- [`aiarmada/signals`](../../signals/docs/01-overview.md) — tracked properties and event attribution source
- [`aiarmada/commerce-support`](../../commerce-support/docs/01-overview.md) — owner scoping and shared utilities
- [`aiarmada/filament-growth`](../../filament-growth/docs/01-overview.md) — Filament admin resources, results pages, and widgets

## Main models services or surfaces

- **Models** — `Experiment`, `Variant`, `Assignment`
- **Runtime surfaces** — middleware, helper, facade, Blade directives, Livewire concern, and event-property enrichment
- **Reporting surface** — `AggregateExperimentMetrics` and winner-ready result summaries

## Owner scoping and security notes

- Experiments, variants, and assignments are owner-scoped by default
- Request-scoped experiment context should remain explicit for jobs, commands, or cross-owner reporting flows
- Signals enrichment should be treated as contextual analytics metadata, not authorization

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

## Read next

- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Usage](04-usage.md)
- [Troubleshooting](99-troubleshooting.md)
- [Filament Growth overview](../../filament-growth/docs/01-overview.md)