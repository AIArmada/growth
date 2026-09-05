---
title: Growth Context
package: growth
status: current
surface: analytics
family: growth-and-incentives
keywords:
  - experiment
  - ab-test
  - variant
  - assignment
  - preset
---

# Growth Context

## Snapshot
- Composer: `aiarmada/growth`
- Role: Revenue experimentation: experiments/variants, sticky assignments, presets, winner metrics on Signals.
- Triggers: experiment, ab-test, variant, assignment, preset
- Search first: `src/Models, src/Actions, config, docs`
- Related: `filament-growth`, `signals`
- Paired: `filament-growth` (Filament admin adapter)

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-growth/CONTEXT.md` when the change crosses UI/domain
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-growth`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: A/B tests and winner measurement.
- Skip when: Raw analytics — see signals; admin UI — see filament-growth.
- Owner/security: Owner-scoped (all 3 models).

## Key surfaces
- Models: `Assignment`, `Experiment`, `Variant`
- Actions/Services: `Actions/AggregateExperimentMetrics`, `Actions/BuildExperimentSignalProperties`, `Actions/ProjectExperimentContextIntoSignalProperties`, `Actions/RepairExperimentAssignment`, `Actions/ResolveExperimentAssignment`, `Actions/ResolveExperimentPreset`, `Actions/ScopeSignalQueryToOwner`, `Support/Context/ExperimentContext`
- Config `growth.php`: `database`, `table_prefix`, `json_column_type`, `tables`, `experiments`, `variants`, `assignments`, `defaults`, `module_type`, `winner_metric`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: `v2.md`
