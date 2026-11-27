<?php

declare(strict_types=1);

namespace AIArmada\Growth\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Growth\Actions\DeleteVariantAssignments;
use AIArmada\Growth\Enums\VariantStatus;
use AIArmada\Growth\Queries\ExperimentResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property string $id
 * @property string $experiment_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $traffic_percentage
 * @property int $position
 * @property bool $is_control
 * @property VariantStatus $status
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $archived_at
 * @property array<string, mixed>|null $settings
 * @property-read Experiment $experiment
 * @property-read Collection<int, Assignment> $assignments
 */
final class Variant extends Model implements Auditable
{
    use HasCommerceAudit {
        transitionTo as private transitionToAudit;
    }
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'growth.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'experiment_id',
        'code',
        'name',
        'description',
        'traffic_percentage',
        'position',
        'is_control',
        'status',
        'activated_at',
        'deactivated_at',
        'retired_at',
        'archived_at',
        'settings',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'traffic_percentage' => 'integer',
        'position' => 'integer',
        'is_control' => 'boolean',
        'status' => VariantStatus::class,
        'activated_at' => 'immutable_datetime',
        'deactivated_at' => 'immutable_datetime',
        'retired_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
        'settings' => 'array',
    ];

    public function getTable(): string
    {
        $tables = config('growth.database.tables', []);
        $prefix = config('growth.database.table_prefix', 'growth_');

        return $tables['variants'] ?? $prefix . 'variants';
    }

    /**
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class, 'experiment_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'variant_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', VariantStatus::Active->value);
    }

    /**
     * @throws InvalidArgumentException when an archived or retired variant would be reopened.
     */
    public function transitionTo(VariantStatus | Audit $status, bool | string | null $notes = null): static
    {
        if ($status instanceof Audit) {
            $this->transitionToAudit($status, is_bool($notes) ? $notes : false);

            return $this;
        }

        if (! $this->exists) {
            $this->status = $status;
            $this->applyLifecycleTimestamp($status);

            return $this;
        }

        if (! $this->status instanceof VariantStatus) {
            throw new InvalidArgumentException('Variant status must be resolved before transitioning.');
        }

        if ($this->status === $status) {
            return $this;
        }

        $allowed = match ($this->status) {
            VariantStatus::Archived => [],
            VariantStatus::Retired => [VariantStatus::Archived],
            default => [
                VariantStatus::Draft,
                VariantStatus::Active,
                VariantStatus::Deactivated,
                VariantStatus::Retired,
                VariantStatus::Archived,
            ],
        };

        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('An archived or retired variant cannot be reopened.');
        }

        $this->status = $status;
        $this->applyLifecycleTimestamp($status);

        return $this;
    }

    private function applyLifecycleTimestamp(VariantStatus $status): void
    {
        $at = CarbonImmutable::now();

        if ($status !== VariantStatus::Deactivated) {
            $this->deactivated_at = null;
        }

        match ($status) {
            VariantStatus::Draft => null,
            VariantStatus::Active => $this->activated_at ??= $at,
            VariantStatus::Deactivated => $this->deactivated_at ??= $at,
            VariantStatus::Retired => $this->retired_at ??= $at,
            VariantStatus::Archived => $this->archived_at ??= $at,
        };
    }

    protected static function booted(): void
    {
        static::creating(function (Variant $variant): void {
            $variant->assertExperimentConsistency();
        });

        static::saving(function (Variant $variant): void {
            if (! $variant->exists) {
                return;
            }

            if ($variant->isDirty('experiment_id')) {
                throw new InvalidArgumentException('Variant experiment_id cannot be changed after creation.');
            }

            $variant->assertExperimentConsistency();
        });

        static::deleting(function (Variant $variant): void {
            app(DeleteVariantAssignments::class)->handle($variant);
        });
    }

    private function assertExperimentConsistency(): void
    {
        $experiment = app(ExperimentResolver::class)->resolve(
            (string) $this->experiment_id,
            message: 'Variant experiment is not accessible in the current owner scope.',
        );

        if (! $this->exists && $this->owner_type === null && $this->owner_id === null) {
            $this->owner_type = $experiment->owner_type;
            $this->owner_id = $experiment->owner_id;
        }

        if ($experiment->owner_type !== $this->owner_type || (string) $experiment->owner_id !== (string) $this->owner_id) {
            throw new InvalidArgumentException('Variant owner must match the parent experiment owner.');
        }
    }
}
