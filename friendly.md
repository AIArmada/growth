## Second pass — 2026-06-09

### Confirmed
- 4 old `Resolve*Experiment*` Actions are gone. Actions directory reduced from 10 to 6 classes.
- `Support/ExperimentResolver.php` exists at `Support/` root, provides strategy+key parameterization.
- Three resolve Actions replaced (`ResolveAccessibleExperiment`, `ResolveReadableExperiment`, `ResolveExperimentAssignment`) with resolver-based calls.
- Phase 2 audit accurate: `BuildExperimentSignalProperties` (low-level, takes Assignment→signal array) and `ProjectExperimentContextIntoSignalProperties` (high-level, composes Build + resolves assignments from source model) are not duplicates.
- `Support/` reorganized: `Context/` (ExperimentContext, ExperimentContextManager), `Request/` (RequestExperimentSubjects). Imports updated.

### Resolved (since second pass)
- **No Console/Commands directory**: ✅ Added in Phase 5 — `RecomputeExperimentAssignmentsCommand` and `ArchiveExperimentsCommand`, both integrated with `OwnerBatchRunner`.
- **ExperimentResolver location inconsistency**: ✅ Fixed in Phase 4 — `ExperimentResolver` moved to `Support/Context/` with BC re-export preserved.

### New findings
1. **Single contract surface is thin but intentional**: Only 1 contract (`RequestExperimentSubjectResolver`) exists. This is not a gap — the package is deliberately small (3 models, 6 Actions, 1 contract). Adding contracts prematurely would over-engineer. The `ExperimentResolver` substitution already proves the package can absorb variants without new contracts.
2. **ResolveExperimentPreset is a leftover edge case**: `Actions/ResolveExperimentPreset.php` survived the collapse (it's not a pair with anything). Audit whether it should also route through `ExperimentResolver`. Currently it operates on "presets" which may be a distinct concept from accessible/readable experiment resolution.
3. **Livewire concern remains single-entry**: `Livewire/Concerns/InteractsWithExperimentContext.php` imports the context directly via the Facade. If the context resolution path changes, this concern needs manual update. A contract-backed resolver would make this extension-safe.

### Updated recommendation
All Phase 4 and Phase 5 items completed — `ExperimentResolver` moved to `Support/Context/`, `ResolveExperimentPreset` audited and kept, `Console/Commands/` added with two batch commands. No further action needed on this pass.

---

# Growth friendliness review

This note reviews `packages/growth` against two repo-level expectations:

- when a capability may grow variants, prefer stable seams such as contracts, metadata, hooks, domain events, resolvers, and support classes
- when orchestration repeats, extract reusable Actions, Services, or Use Cases so the package stays friendly to multiple entrypoints

## What I reviewed

- `src/Actions` (10 classes)
- `src/Support`
- `src/Http/Middleware`
- `src/Models` (3 classes)
- `src/Settings`
- `src/Livewire/Concerns`
- `src/Contracts`
- downstream consumers in `signals`, `affiliates`, `cart`, `checkout`, `events`

## What is already friendly

### Actions are organized and well-named

- `Actions/AggregateExperimentMetrics`
- `Actions/BuildExperimentSignalProperties`
- `Actions/ProjectExperimentContextIntoSignalProperties`
- `Actions/ResolveAccessibleExperiment`
- `Actions/ResolveAccessibleExperimentBySlug`
- `Actions/ResolveExperimentAssignment`
- `Actions/ResolveExperimentPreset`
- `Actions/ResolveReadableExperiment`
- `Actions/ResolveReadableExperimentBySlug`
- `Actions/ScopeSignalQueryToOwner`

The Action tree is focused and follows the monorepo's "Actions only" rule. Each Action is a single workflow step.

### Request-scoped experiment context is a real seam

- `Support/ExperimentContextManager.php`
- `Support/ExperimentContext.php`
- `Support/RequestExperimentSubjects.php`
- `Http/Middleware/ResolveExperiment.php`

The middleware binds the experiment context on the request, and the manager resolves it. This is the right shape for a request-scoped state seam.

### Livewire concern integrates the package with Filament

- `Livewire/Concerns/InteractsWithExperimentContext.php`

The package has a clear entry point for Livewire components to consume the context.

### Settings is one class

- `Settings/GrowthSettings.php`

Single settings class keeps the config surface tight.

## Findings

### 1. `ResolveAccessibleExperiment`, `ResolveReadableExperiment`, and their slug variants are likely near-duplicates

**Files**

- `Actions/ResolveAccessibleExperiment.php`
- `Actions/ResolveAccessibleExperimentBySlug.php`
- `Actions/ResolveReadableExperiment.php`
- `Actions/ResolveReadableExperimentBySlug.php`

**Why this hurts friendliness**

Four Resolve Actions that differ only by (accessibility vs readability) and (ID vs slug). This is the canonical "two-by-two duplication" smell.

**Recommendation**

Add a small `Support/ExperimentResolver` that takes a strategy parameter (accessible/readable) and a key (id/slug). The four Actions become thin adapters or are replaced by direct calls to the resolver.

### 2. No `Services/` directory — context lives in `Support/`

**Files**

- `Support/ExperimentContextManager.php`
- `Support/ExperimentContext.php`
- `Support/RequestExperimentSubjects.php`

**Why this hurts friendliness**

The `Support/` folder mixes context management, request subjects, and helpers. The naming convention is fine, but a clear separation between "state" and "support" would help.

**Recommendation**

Consider grouping:

- `Support/Context/` (ExperimentContext, ExperimentContextManager)
- `Support/Request/` (RequestExperimentSubjects)

Or leave as-is and document.

### 3. The `RequestExperimentSubjectResolver` contract is the right shape

**Files**

- `Contracts/RequestExperimentSubjectResolver.php`

**Why this is worth noting**

This is a real contract. The default implementation is in `Support/`. New subject resolvers (cookie-based, header-based, user-property-based) can implement the contract.

### 4. `AggregateExperimentMetrics` and `ScopeSignalQueryToOwner` are unique

**Files**

- `Actions/AggregateExperimentMetrics.php`
- `Actions/ScopeSignalQueryToOwner.php`

**Why this is worth noting**

These two Actions are not duplicated and they show the package's two real workflows:

- aggregate metrics (analytics)
- scope signals (signals integration)

This is the right shape — analytics and signals integration are separate concerns.

### 5. Models are minimal (3)

**Files**

- `Models/Experiment.php`
- `Models/Variant.php`
- `Models/Assignment.php`

**Why this is worth noting**

The data model is small. This is a focused package. Keep this discipline.

### 6. `BuildExperimentSignalProperties` and `ProjectExperimentContextIntoSignalProperties` are likely related

**Files**

- `Actions/BuildExperimentSignalProperties.php`
- `Actions/ProjectExperimentContextIntoSignalProperties.php`

**Why this hurts friendliness**

Two Actions that both produce signal properties. One may be the lower-level builder and the other the projector. If both are needed, document the relationship.

**Recommendation**

Audit both. If `Project` wraps `Build`, document the layering. If they overlap, collapse.

### 7. No `Console/Commands` directory

**Why this hurts friendliness**

Bulk operations (assignment recomputation, experiment archival) have no clear owner.

**Recommendation**

Add a `src/Console/Commands` directory when the first batch operation is needed.

## Concrete refactor plan

### Phase 1 — collapse the four Resolve Actions

**Steps**

1. Add `Support/ExperimentResolver` with strategy and key parameters.
2. Replace the four Actions with thin adapters or remove them.
3. Update callers.

### Phase 2 — audit signal properties Actions

**Steps**

1. Compare `BuildExperimentSignalProperties` to `ProjectExperimentContextIntoSignalProperties`.
2. Pick the canonical owner.
3. Document the relationship.

### Phase 3 — organize `Support/`

**Steps**

1. Consider grouping by domain.
2. Update imports.





## Refactor tracking

This checklist tracks progress on the refactor plan above. Each item lists a concrete phase/step.
Agents: claim an item by updating its status. Use `@agent-name` to claim ownership.

Status legend:
- `[pending]` — not started
- `[in-progress]` — being worked on
- `[done]` — completed and verified
- `[blocked]` — blocked by another item

### Phase 1 — collapse the four Resolve Actions

- [done] Add `Support/ExperimentResolver` with strategy and key parameters.
- [done] Replace the four Actions with thin adapters or remove them.
- [done] Update callers.

### Phase 2 — audit signal properties Actions

- [done] Compare `BuildExperimentSignalProperties` to `ProjectExperimentContextIntoSignalProperties`.
- [done] Pick the canonical owner.
- [done] Document the relationship.

**Audit results:**

1. `BuildExperimentSignalProperties` is the low-level builder — takes an `Assignment` and produces a signal property array (`experiment_id`, `experiment_slug`, `variant_id`, `variant_code`, `assignment_id`, `module_type`).
2. `ProjectExperimentContextIntoSignalProperties` is the high-level orchestrator — composes `BuildExperimentSignalProperties`, resolves assignments and identities for a source model, and merges experiment context into tracked property events.
3. **Not duplicates.** Build is the primitive; Project wraps it with signal-enrichment orchestration. The dependency is directional: `Project → Build`.

### Phase 3 — organize `Support/`

- [done] Group into `Support/Context/` (ExperimentContext, ExperimentContextManager) and `Support/Request/` (RequestExperimentSubjects).
- [done] Update all imports across monorepo.

### Phase 4 — resolve location and edge cases

- [done] Move `ExperimentResolver.php` from `Support/` root into `Support/Context/` for consistency with `ExperimentContext`. BC re-export preserved at old path.
- [done] Audit `ResolveExperimentPreset` to confirm it is a genuinely distinct concept from other experiment resolution Actions (not collapsible into `ExperimentResolver`).

**Audit result:** `ResolveExperimentPreset` works with `ExperimentModuleType` enums and their preset configurations (module type, goal event, winner metric, settings). `ExperimentResolver` resolves `Experiment` models by ID/slug with owner scoping. The two classes solve entirely different problems and cannot be collapsed.

- [done] Update all imports across monorepo after `ExperimentResolver` move (6 files in growth package updated).

### Phase 5 — prepare for batch operations

- [done] Add `Console/Commands/` directory with `RecomputeExperimentAssignmentsCommand` and `ArchiveExperimentsCommand`.
- [done] Integrate new batch commands with `OwnerBatchRunner` from `commerce-support`. Registered in `GrowthServiceProvider`.



## Suggested verification scope

- per-Action tests
- context manager tests
- middleware tests
- cross-package tests for signals/affiliates

## Recommended first move

Phase 1 — collapse the four Resolve Actions. The two-by-two duplication is the most visible smell and the cleanup is mostly mechanical.
