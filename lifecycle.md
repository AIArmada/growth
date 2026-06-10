# Growth Package — Lifecycle & Schema Audit

---

## 1. Executive Summary

The growth package (`aiarmada/growth`) manages A/B experiment primitives across three tables:
`growth_experiments`, `growth_variants`, and `growth_assignments`. The current schema is
inconsistent with the project's lifecycle principles:

- **Experiment status** is a flat enum but the archive command references enum cases that
  **do not exist** in the current `ExperimentStatus` — this is a latent runtime error.
- **Variant** uses `is_active` boolean instead of a `status` column with `*_at` timestampTz columns.
- **Variant has no lifecycle status** — only a boolean toggle.
- **No `archived_at` timestamp** — the archive command changes `status` string but leaves no
  timestamp trail.
- **Missing lifecycle timestamps** on experiments (`paused_at`, `concluded_at`) and variants
  (`activated_at`, `deactivated_at`, `retired_at`).
- **Assignment** is already timestamp-aware (`assigned_at`, `first_exposed_at`, `last_seen_at`)
  and needs minimal changes.
- `is_control` on variants is a structural designation flag — keep as boolean.

This document inventories every table column, enumerates problems, recommends the target
schema, and provides a refactoring plan broken into parallel agent tasks.

---

## 2. Full Inventory by Table

### 2.1 `growth_experiments`

| Column               | Type                     | Nullable | Default                   | Notes                                      |
|----------------------|--------------------------|----------|---------------------------|--------------------------------------------|
| `id`                 | `uuid`                   | no       | PK                        |                                            |
| `tracked_property_id`| `foreignUuid`            | no       | —                         | Indexed `(tracked_property_id, status)`    |
| `owner_type`         | `uuidMorphs`             | yes      | —                         | `nullableUuidMorphs('owner')`              |
| `owner_id`           | `uuidMorphs`             | yes      | —                         |                                            |
| `owner_scope`        | `string`                 | no       | `'global'`                | Unique `(owner_scope, slug)`               |
| `name`               | `string`                 | no       | —                         |                                            |
| `slug`               | `string`                 | no       | —                         | Auto-generated from name in `booted()`    |
| `description`        | `text`                   | yes      | —                         |                                            |
| `module_type`        | `string`                 | no       | `'ab_test'`               | Backed by `ExperimentModuleType` enum      |
| `status`             | `string`                 | no       | `'draft'`                 | Backed by `ExperimentStatus` enum (4 cases)|
| `goal_event_name`    | `string`                 | no       | `'order.paid'`            |                                            |
| `goal_event_category`| `string`                 | no       | `'conversion'`            |                                            |
| `winner_metric`      | `string`                 | no       | `'revenue_per_visitor'`   |                                            |
| `audience`           | `jsonb`                  | yes      | —                         |                                            |
| `settings`           | `jsonb`                  | yes      | —                         |                                            |
| `started_at`         | `timestampTz`            | yes      | —                         |                                            |
| `ended_at`           | `timestampTz`            | yes      | —                         |                                            |
| `created_at`         | `timestampTz`            | no       | —                         |                                            |
| `updated_at`         | `timestampTz`            | no       | —                         |                                            |

**Current `ExperimentStatus` enum values:** `draft`, `active`, `paused`, `concluded`

**Current `ExperimentModuleType` enum values:** `ab_test`, `sales_page_test`, `funnel_test`, `pricing_test`

---

### 2.2 `growth_variants`

| Column               | Type                     | Nullable | Default   | Notes                                      |
|----------------------|--------------------------|----------|-----------|--------------------------------------------|
| `id`                 | `uuid`                   | no       | PK        |                                            |
| `experiment_id`      | `foreignUuid`            | no       | —         | Unique `(experiment_id, code)`            |
| `owner_type`         | `uuidMorphs`             | yes      | —         | `nullableUuidMorphs('owner')`              |
| `owner_id`           | `uuidMorphs`             | yes      | —         |                                            |
| `code`               | `string`                 | no       | —         |                                            |
| `name`               | `string`                 | no       | —         |                                            |
| `description`        | `text`                   | yes      | —         |                                            |
| `traffic_percentage` | `unsignedInteger`        | no       | `50`      |                                            |
| `position`           | `unsignedInteger`        | no       | `0`       | Indexed `(experiment_id, position)`       |
| `is_control`          | `boolean`                | no       | `false`   | **OK** — structural designation, keep boolean |
| `is_active`          | `boolean`                | no       | `true`    | **Violation — boolean lifecycle flag**     |
| `settings`           | `jsonb`                  | yes      | —         |                                            |
| `created_at`         | `timestampTz`            | no       | —         |                                            |
| `updated_at`         | `timestampTz`            | no       | —         |                                            |

---

### 2.3 `growth_assignments`

| Column               | Type                     | Nullable | Default   | Notes                                      |
|----------------------|--------------------------|----------|-----------|--------------------------------------------|
| `id`                 | `uuid`                   | no       | PK        |                                            |
| `experiment_id`      | `foreignUuid`            | no       | —         | Unique `(experiment_id, subject_key)`      |
| `variant_id`         | `foreignUuid`            | no       | —         | Indexed `(experiment_id, variant_id)`      |
| `signal_identity_id` | `foreignUuid`            | yes      | —         | Unique `(experiment_id, signal_identity_id)`|
| `signal_session_id`  | `foreignUuid`            | yes      | —         | Unique `(experiment_id, signal_session_id)` |
| `owner_type`         | `uuidMorphs`             | yes      | —         | `nullableUuidMorphs('owner')`              |
| `owner_id`           | `uuidMorphs`             | yes      | —         |                                            |
| `subject_key`        | `string`                 | no       | —         |                                            |
| `bucket`             | `unsignedInteger`        | no       | `0`       |                                            |
| `metadata`           | `jsonb`                  | yes      | —         |                                            |
| `assigned_at`        | `timestampTz`            | no       | —         | Auto-set in `booted()`                     |
| `first_exposed_at`   | `timestampTz`            | yes      | —         | Auto-set in `booted()`                     |
| `last_seen_at`       | `timestampTz`            | yes      | —         | Auto-set in `booted()`                     |
| `created_at`         | `timestampTz`            | no       | —         |                                            |
| `updated_at`         | `timestampTz`            | no       | —         |                                            |

> Assignment is the **best-structured** table in the package — already uses timestampTz
> lifecycle columns instead of booleans. No changes needed.

---

## 3. Problems Summary

### P1 — CRITICAL: ExperimentStatus / ArchiveCommand mismatch

**File:** `src/Console/Commands/ArchiveExperimentsCommand.php:38-39`

```php
ExperimentStatus::Completed->value,  // ❌ Does not exist
ExperimentStatus::Cancelled->value,  // ❌ Does not exist
...
ExperimentStatus::Archived           // ❌ Does not exist
```

The `ExperimentStatus` enum defines only: `Draft`, `Active`, `Paused`, `Concluded`.
The archive command uses three cases that **do not exist in the enum or the database**.
This will throw a fatal `Error` at runtime when the command runs. The status lifecycle
must be expanded and the command fixed.

### P2 — HIGH: Variant `is_active` boolean instead of lifecycle status

`is_active` answers "is this variant active?" Variants should carry a `status` column with
proper lifecycle states (e.g., `draft`, `active`, `paused`, `retired`) and corresponding
`*_at` transition timestamps.

### P3 — HIGH: Variant has no lifecycle status

Variants currently have no status concept — only `is_active`. They should carry a
`status` column with proper lifecycle states that mirrors the experiment lifecycle or
adds variant-specific states.

### P4 — MEDIUM: No `archived_at` timestamp on experiments

The `ArchiveExperimentsCommand` changes `status` to `archived` (which doesn't exist yet)
but records no timestamp. Add `archived_at timestampTz nullable`.

### P5 — MEDIUM: Missing lifecycle timestamps on experiments

- `paused_at` — when experiment was last paused (resettable on resume)
- `concluded_at` — when experiment was concluded (immutable)

### P6 — LOW: No lifecycle timestamps on variants

- `activated_at` — when variant became active
- `deactivated_at` — when variant was deactivated
- `retired_at` — when variant was permanently retired

### P7 — LOW: `started_at` / `ended_at` semantics are overloaded

Current `started_at` is used as "when the experiment began running". With proper
lifecycle transitions, this should be derived from status transition timestamps
(`activated_at`) rather than being manually set.

---

## 4. Recommended Structure

### 4.1 Revised `ExperimentStatus` enum

```php
enum ExperimentStatus: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case Paused    = 'paused';
    case Concluded = 'concluded';
    case Archived  = 'archived';
}
```

**Lifecycle transitions:**
```
Draft ──► Active ──► Paused ──► Active (resume)
  │         │           │
  │         │           └──► Concluded
  │         │
  │         └──► Concluded
  │
  └──► Concluded

Concluded ──► Archived (only after N days, via command)
```

**Rules:**
- `Draft` → `Active`: sets `started_at` to now (if not already set)
- `Active` → `Paused`: sets `paused_at`
- `Paused` → `Active`: clears `paused_at` to null
- Any → `Concluded`: sets `ended_at` and `concluded_at` to now (immutable)
- `Concluded` → `Archived`: sets `archived_at` to now (irreversible)

### 4.2 Revised `growth_experiments` table

**Add columns:**
| Column          | Type          | Nullable | Notes                            |
|-----------------|---------------|----------|----------------------------------|
| `archived_at`   | `timestampTz` | yes      | Set on archival                  |
| `paused_at`     | `timestampTz` | yes      | Set on pause, cleared on resume  |
| `concluded_at`  | `timestampTz` | yes      | Set on conclusion (immutable)    |

**Remove:**
- Nothing removed; `started_at` and `ended_at` retained as migration-phase compat
  (derived from transition timestamps in the future).

### 4.3 Revised `VariantStatus` enum (new)

```php
enum VariantStatus: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case Paused    = 'paused';
    case Retired   = 'retired';
    case Archived  = 'archived';
}
```

**Lifecycle transitions:**
```
Draft ──► Active ──► Paused ──► Active (resume)
  │         │           │
  │         │           └──► Retired
  │         │
  │         └──► Retired
  │
  └──► Retired

Retired ──► Archived
```

### 4.4 Revised `growth_variants` table

**Add columns:**
| Column            | Type          | Nullable | Notes                                |
|-------------------|---------------|----------|--------------------------------------|
| `status`          | `string`      | no       | `'draft'` default (VariantStatus)    |
| `activated_at`    | `timestampTz` | yes      | When status became active            |
| `deactivated_at`  | `timestampTz` | yes      | When last deactivated/paused         |
| `retired_at`      | `timestampTz` | yes      | When retired                         |
| `archived_at`     | `timestampTz` | yes      | When archived                        |

**Remove columns:**
| Column          | Reason                                                  |
|-----------------|---------------------------------------------------------|
| `is_active`     | Replaced by `status` + `activated_at`/`deactivated_at`  |

`is_control` stays as boolean (structural designation flag).

**Remove indexes:**
- `(experiment_id, is_active)` — replaced by `(experiment_id, status)`

**Add indexes:**
- `(experiment_id, status)`

### 4.5 Revised `growth_assignments` table

No changes needed — assignment is already well-structured with `assigned_at`, `first_exposed_at`, `last_seen_at`.

### 4.6 Model changes

**Experiment model:**
- Add `status` transition methods: `activate()`, `pause()`, `resume()`, `conclude()`, `archive()`
- These set corresponding `*_at` timestamps and validate transition legality
- Move status-mutation logic from `ArchiveExperimentsCommand` into the model
- Cast `archived_at`, `paused_at`, `concluded_at` as `immutable_datetime`

**Variant model:**
- Remove `is_active` casts/attributes
- Add `status` cast to `VariantStatus` enum
- Add `activated_at`, `deactivated_at`, `retired_at`, `archived_at` casts
- Update `scopeActive()` to use `status` instead of `is_active`
- Add status transition methods mirroring experiment

---

## 5. Refactoring Plan — Parallel-Agent Checklist

Each `<agent>` task below is independently grabbable. Order within a phase matters;
phases are sequential.

### Phase 0 — Enums & Foundation (blocks all others)

| #   | Agent Task                                                              | Files                                                           |
|-----|-------------------------------------------------------------------------|-----------------------------------------------------------------|
| A0  | Add `Archived` case to `ExperimentStatus`                               | `src/Enums/ExperimentStatus.php`                                |
| A1  | Create `VariantStatus` enum (`Draft`, `Active`, `Paused`, `Retired`, `Archived`) | `src/Enums/VariantStatus.php` (new)                             |

### Phase 1 — Migrations (parallel-after A0–A1)

| #   | Agent Task                                                              | Files                                                           |
|-----|-------------------------------------------------------------------------|-----------------------------------------------------------------|
| B0  | Create migration: add columns to `growth_experiments`                   | `database/migrations/*_add_lifecycle_to_growth_experiments.php` |
| B1  | Create migration: rework `growth_variants` (add status/timestamps, drop `is_active`) | `database/migrations/*_rework_growth_variants_lifecycle.php`    |

### Phase 2 — Models (parallel after B0–B1)

| #   | Agent Task                                                              | Files                                                           |
|-----|-------------------------------------------------------------------------|-----------------------------------------------------------------|
| C0  | Update `Experiment` model: new casts, transition methods, fix `booted()` | `src/Models/Experiment.php`                                     |
| C1  | Update `Variant` model: remove `is_active`, add status + timestamps, fix `scopeActive()` | `src/Models/Variant.php`                                        |

### Phase 3 — References / Command Fix (parallel after C0–C1)

| #   | Agent Task                                                              | Files                                                           |
|-----|-------------------------------------------------------------------------|-----------------------------------------------------------------|
| D0  | Fix `ArchiveExperimentsCommand` to use actual enum cases + archived_at  | `src/Console/Commands/ArchiveExperimentsCommand.php`            |
| D1  | Update any other references to `is_active`, old statuses                | Full `src/` sweep                                              |

### Phase 4 — Tests (parallel after D0–D1)

| #   | Agent Task                                                              | Files                                                           |
|-----|-------------------------------------------------------------------------|-----------------------------------------------------------------|
| E0  | Test `ExperimentStatus` transitions + timestamp behavior                | `tests/Unit/ExperimentStatusTest.php` (new)                     |
| E1  | Test `VariantStatus` transitions                                        | `tests/Unit/VariantStatusTest.php` (new)                        |
| E2  | Test `ArchiveExperimentsCommand` with real enum cases                   | `tests/Feature/ArchiveExperimentsCommandTest.php`               |
| E3  | Update existing model/variant tests for schema changes                  | `tests/` grep for `is_active`                                   |

---

## 6. Migration Strategy

### Principle: No backward compatibility.

All changes are breaking. The package is in beta — breaking changes are permitted per the
repo's beta policy.

### Data migration plan (for `growth_variants`):

**`is_active` → `status` + timestamps:**
```sql
-- Active variants stay 'active'
UPDATE growth_variants
SET status = 'active',
    activated_at = COALESCE(activated_at, created_at)
WHERE is_active = true;

-- Inactive variants become 'paused' (or 'retired' if parent experiment is concluded)
UPDATE growth_variants
SET status = 'paused',
    deactivated_at = updated_at
WHERE is_active = false;
```

**For `growth_experiments` `archived_at` backfill:**
```sql
-- If any concluded experiments exist with updated_at beyond threshold, set archived_at
UPDATE growth_experiments
SET archived_at = updated_at
WHERE status = 'archived' AND archived_at IS NULL;
```

### Rollback strategy:

No rollback migrations. Since this is beta and breaking, the `down()` methods should be
empty (per project convention of no `down()` required).

### Deployment order:

1. Deploy enums + config (Phase 0)
2. Deploy migrations (Phase 1)
3. Deploy models (Phase 2)
4. Deploy references + command fix (Phase 3)
5. Deploy tests (Phase 4)

Phases 1–2 must deploy together atomically (migrations + models that read the new
columns). Phases 0 and 3–4 can ship independently.

---

## 7. Verification Commands

### 7.1 Migration verification

```bash
# Check no FK constraints or cascades slipped in
rg -n -- 'constrained\(|cascadeOnDelete\(' packages/growth/database

# Check no is_active remain in migrations (except backfill)
rg -n -- 'is_active' packages/growth/database

# Confirm all new columns use timestampTz
rg -n -- 'timestampTz' packages/growth/database

# Confirm archived_at, paused_at, concluded_at, activated_at,
# deactivated_at, retired_at exist where expected
rg -n -- 'archived_at|paused_at|concluded_at|activated_at|deactivated_at|retired_at' packages/growth/database
```

### 7.2 Model verification

```bash
# No is_active casts remain in models (except is_control which stays)
rg -n -- 'is_active' packages/growth/src/Models

# All new timestamps are in casts arrays
rg -n -- 'activated_at|deactivated_at|retired_at|archived_at|paused_at|concluded_at' packages/growth/src/Models

# scopeActive uses status not is_active
rg -n -- 'scopeActive|::active\b' packages/growth/src
```

### 7.3 Command verification

```bash
# Archive command references only valid ExperimentStatus cases
rg -n -- 'ExperimentStatus::' packages/growth/src/Console/Commands

# Verify the command no longer references Completed, Cancelled
rg -n -- 'Completed|Cancelled' packages/growth/src/Console/Commands
# Expected: no matches
```

### 7.4 PHPStan

```bash
./vendor/bin/phpstan analyse packages/growth/src --level=6
```

### 7.5 Tests

```bash
# Run per package with --parallel
./vendor/bin/pest --parallel packages/growth/tests

# Run specific lifecycle tests
./vendor/bin/pest --parallel packages/growth/tests --filter=ExperimentStatus
./vendor/bin/pest --parallel packages/growth/tests --filter=VariantStatus
```

### 7.6 Full sweep for old status references

```bash
# Ensure no code references the non-existent Completed / Cancelled cases
rg -n -- 'ExperimentStatus::Completed|ExperimentStatus::Cancelled' packages/growth/src
# Expected: no matches

# Ensure no is_active remain outside migration data backfill
rg -n -- '\bis_active\b' packages/growth/src
# Expected: only in migration data-backfill comments
```

---

## Appendix: Config changes required

Under a new `lifecycle` section:

```php
'lifecycle' => [
    'archive_after_days' => 90,
    'auto_archive_on_conclude' => false,
],
```
