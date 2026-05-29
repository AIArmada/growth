<?php

declare(strict_types=1);

namespace AIArmada\Growth\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeKey;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeKey;
use AIArmada\Growth\Actions\ResolveExperimentPreset;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Growth\Enums\ExperimentModuleType;
use AIArmada\Growth\Enums\ExperimentStatus;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * @property string $id
 * @property string $tracked_property_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $owner_scope
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $module_type
 * @property ExperimentStatus $status
 * @property string $goal_event_name
 * @property string $goal_event_category
 * @property string $winner_metric
 * @property array<string, mixed>|null $audience
 * @property array<string, mixed>|null $settings
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property-read TrackedProperty $trackedProperty
 * @property-read Collection<int, Variant> $variants
 * @property-read Collection<int, Assignment> $assignments
 */
final class Experiment extends Model implements Auditable
{
    use HasCommerceAudit;
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasOwnerScopeKey {
        resolveOwnerScopeKey as private resolveBaseOwnerScopeKey;
    }
    use HasUuids;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'growth.features.owner';

    /** @var list<string> */
    protected $hidden = [
        'owner_scope',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tracked_property_id',
        'name',
        'slug',
        'description',
        'module_type',
        'status',
        'goal_event_name',
        'goal_event_category',
        'winner_metric',
        'audience',
        'settings',
        'started_at',
        'ended_at',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => ExperimentStatus::class,
        'audience' => 'array',
        'settings' => 'array',
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        $tables = config('growth.database.tables', []);
        $prefix = config('growth.database.table_prefix', 'growth_');

        return $tables['experiments'] ?? $prefix . 'experiments';
    }

    /**
     * @return BelongsTo<TrackedProperty, $this>
     */
    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class, 'experiment_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'experiment_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ExperimentStatus::Active->value);
    }

    protected static function booted(): void
    {
        static::creating(function (Experiment $experiment): void {
            if (! is_string($experiment->slug) || $experiment->slug === '') {
                $experiment->slug = Str::slug($experiment->name);
            }

            if (! is_string($experiment->module_type) || $experiment->module_type === '') {
                $experiment->module_type = (string) config('growth.defaults.module_type', 'ab_test');
            }

            $preset = null;

            if (config('growth.features.preset_modules.enabled', true)) {
                $supportedModuleType = ExperimentModuleType::tryFrom($experiment->module_type);

                if ($supportedModuleType instanceof ExperimentModuleType) {
                    $preset = app(ResolveExperimentPreset::class)->handle($supportedModuleType->value);
                    $experiment->module_type = $supportedModuleType->value;
                }
            }

            if (! is_string($experiment->goal_event_name) || $experiment->goal_event_name === '') {
                $experiment->goal_event_name = is_array($preset)
                    ? (string) $preset['goal_event_name']
                    : (string) config('growth.integrations.signals.purchase_event_name', 'order.paid');
            }

            if (! is_string($experiment->goal_event_category) || $experiment->goal_event_category === '') {
                $experiment->goal_event_category = is_array($preset)
                    ? (string) $preset['goal_event_category']
                    : 'conversion';
            }

            if (! is_string($experiment->winner_metric) || $experiment->winner_metric === '') {
                $experiment->winner_metric = is_array($preset)
                    ? (string) $preset['winner_metric']
                    : (string) config('growth.defaults.winner_metric', 'revenue_per_visitor');
            }

            static::applyPresetSettings($experiment, $preset);
        });

        static::saving(function (Experiment $experiment): void {
            static::applySupportedPresetSettings($experiment);

            if ($experiment->tracked_property_id === '' || $experiment->tracked_property_id === null) {
                throw new RuntimeException('tracked_property_id is required for a growth experiment.');
            }

            if ($experiment->exists && $experiment->isDirty('tracked_property_id')) {
                throw new InvalidArgumentException('Growth experiment tracked_property_id cannot be changed after creation.');
            }

            if (! static::trackedPropertyExists($experiment)) {
                $message = static::resolveOwnerScopeConfig()->enabled || TrackedProperty::ownerScopeConfig()->enabled
                    ? 'Invalid tracked_property_id: does not belong to the current owner scope.'
                    : 'Invalid tracked_property_id: tracked property does not exist.';

                throw new RuntimeException($message);
            }
        });

        static::deleting(function (Experiment $experiment): void {
            $experiment->assignments()->delete();
            $experiment->variants()->each(static function (Variant $variant): void {
                $variant->delete();
            });
        });
    }

    protected static function resolveOwnerScopeKey(Model $model): string
    {
        if (static::resolveOwnerScopeConfig()->enabled || TrackedProperty::ownerScopeConfig()->enabled) {
            return static::resolveBaseOwnerScopeKey($model);
        }

        $ownerType = $model->getAttribute(static::ownerTypeColumnName());
        $ownerId = $model->getAttribute(static::ownerIdColumnName());

        return OwnerScopeKey::forTypeAndId(
            is_string($ownerType) ? $ownerType : null,
            is_int($ownerId) || is_string($ownerId) ? $ownerId : null,
        );
    }

    /**
     * @param  array{settings?: array<string, mixed>}|null  $preset
     */
    private static function applyPresetSettings(Experiment $experiment, ?array $preset): void
    {
        if (! is_array($preset)) {
            return;
        }

        $presetSettings = is_array($preset['settings'] ?? null) ? $preset['settings'] : [];

        if ($presetSettings === []) {
            $experiment->settings = null;

            return;
        }

        $existingSettings = is_array($experiment->settings) ? $experiment->settings : [];
        $normalizedSettings = [];

        foreach ($presetSettings as $key => $defaultValue) {
            $existingValue = $existingSettings[$key] ?? null;

            if (is_array($defaultValue)) {
                $normalizedSettings[$key] = is_array($existingValue)
                    ? array_replace_recursive($defaultValue, $existingValue)
                    : $defaultValue;

                continue;
            }

            $normalizedSettings[$key] = $existingValue ?? $defaultValue;
        }

        $experiment->settings = $normalizedSettings;
    }

    private static function applySupportedPresetSettings(Experiment $experiment): void
    {
        if (! config('growth.features.preset_modules.enabled', true)) {
            return;
        }

        $supportedModuleType = ExperimentModuleType::tryFrom((string) $experiment->module_type);

        if (! $supportedModuleType instanceof ExperimentModuleType) {
            return;
        }

        /** @var array{settings?: array<string, mixed>} $preset */
        $preset = app(ResolveExperimentPreset::class)->handle($supportedModuleType->value);

        static::applyPresetSettings($experiment, $preset);
    }

    private static function trackedPropertyExists(Experiment $experiment): bool
    {
        if (! static::resolveOwnerScopeConfig()->enabled && ! TrackedProperty::ownerScopeConfig()->enabled) {
            return TrackedProperty::query()
                ->whereKey($experiment->tracked_property_id)
                ->exists();
        }

        $owner = OwnerContext::resolve();

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            'Owner scoping is enabled but no owner was resolved while saving a growth experiment.',
        );

        return app(ScopeSignalQueryToOwner::class)
            ->handle(TrackedProperty::query(), $owner)
            ->whereKey($experiment->tracked_property_id)
            ->exists();
    }
}
