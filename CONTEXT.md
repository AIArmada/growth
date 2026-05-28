---
title: Growth Context
package: growth
status: current
surface: analytics
family: growth-and-incentives
---

# Growth Context

## Snapshot
- Composer: `aiarmada/growth`
- Role: Experimentation primitives, sticky assignments, and winner metrics.
- Search first: `src/Models`, `src/Actions`, `src/Services`, `src/Events`, `config`, `docs`
- Related: `filament-growth`, `signals`

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-growth/CONTEXT.md` when admin UI changes are involved
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns ingestion, aggregation, alerting, experiment, or winner-selection logic.
- Audit paired Filament reporting/admin surfaces when analytics behavior changes.
- Update `docs/*.md` in the same pass when public behavior or config changes.
