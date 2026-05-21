<?php

declare(strict_types=1);

namespace AIArmada\Growth\Models;

use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Growth\Actions\ResolveAccessibleExperiment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

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
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property-read Experiment $experiment
 * @property-read Collection<int, Assignment> $assignments
 */
final class Variant extends Model
{
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

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
        'is_active',
        'settings',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'traffic_percentage' => 'integer',
        'position' => 'integer',
        'is_control' => 'boolean',
        'is_active' => 'boolean',
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
        return $query->where('is_active', true);
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
            $variant->assignments()->delete();
        });
    }

    private function assertExperimentConsistency(): void
    {
        $experiment = app(ResolveAccessibleExperiment::class)->handle(
            (string) $this->experiment_id,
            'Variant experiment is not accessible in the current owner scope.',
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
