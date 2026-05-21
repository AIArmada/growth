<?php

declare(strict_types=1);

namespace AIArmada\Growth\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeConfig;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Growth\Actions\ResolveAccessibleExperiment;
use AIArmada\Growth\Actions\ScopeSignalQueryToOwner;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $experiment_id
 * @property string $variant_id
 * @property string|null $signal_identity_id
 * @property string|null $signal_session_id
 * @property string $subject_key
 * @property int $bucket
 * @property array<string, mixed>|null $metadata
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $first_exposed_at
 * @property CarbonImmutable|null $last_seen_at
 * @property-read Experiment $experiment
 * @property-read Variant $variant
 * @property-read SignalIdentity|null $identity
 * @property-read SignalSession|null $session
 */
final class Assignment extends Model
{
    use HasFactory;
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'growth.features.owner';

    /** @var list<string> */
    protected $fillable = [
        'experiment_id',
        'variant_id',
        'signal_identity_id',
        'signal_session_id',
        'subject_key',
        'bucket',
        'metadata',
        'assigned_at',
        'first_exposed_at',
        'last_seen_at',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'bucket' => 'integer',
        'metadata' => 'array',
        'assigned_at' => 'immutable_datetime',
        'first_exposed_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        $tables = config('growth.database.tables', []);
        $prefix = config('growth.database.table_prefix', 'growth_');

        return $tables['assignments'] ?? $prefix . 'assignments';
    }

    /**
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class, 'experiment_id');
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<SignalIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(SignalIdentity::class, 'signal_identity_id');
    }

    /**
     * @return BelongsTo<SignalSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SignalSession::class, 'signal_session_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Assignment $assignment): void {
            $assignment->assigned_at ??= CarbonImmutable::now();
            $assignment->first_exposed_at ??= $assignment->assigned_at;
            $assignment->last_seen_at ??= $assignment->assigned_at;
            $assignment->assertExperimentAndVariantConsistency();
        });

        static::saving(function (Assignment $assignment): void {
            if (! $assignment->exists) {
                return;
            }

            if ($assignment->isDirty('experiment_id')) {
                throw new InvalidArgumentException('Assignment experiment_id cannot be changed after creation.');
            }

            if ($assignment->isDirty('variant_id')) {
                throw new InvalidArgumentException('Assignment variant_id cannot be changed after creation.');
            }

            $assignment->assertExperimentAndVariantConsistency();
        });
    }

    private function assertExperimentAndVariantConsistency(): void
    {
        $experiment = app(ResolveAccessibleExperiment::class)->handle(
            (string) $this->experiment_id,
            'Assignment experiment is not accessible in the current owner scope.',
        );

        if (! $this->exists && $this->owner_type === null && $this->owner_id === null) {
            $this->owner_type = $experiment->owner_type;
            $this->owner_id = $experiment->owner_id;
        }

        /** @var Variant $variant */
        $variant = $this->resolveAccessibleModel(
            Variant::class,
            (string) $this->variant_id,
            'Assignment variant is not accessible in the current owner scope.',
        );

        if ($variant->experiment_id !== $experiment->getKey()) {
            throw new InvalidArgumentException('Assignment variant must belong to the same experiment.');
        }

        $resolvedIdentityId = null;

        if (is_string($this->signal_identity_id) && $this->signal_identity_id !== '') {
            $identity = $this->resolveSignalIdentityForExperiment(
                $experiment,
                $this->signal_identity_id,
                'Assignment signal identity is not accessible in the current owner scope.',
            );

            if ($identity->tracked_property_id !== $experiment->tracked_property_id) {
                throw new InvalidArgumentException('Assignment signal identity must belong to the same tracked property as the experiment.');
            }

            $resolvedIdentityId = (string) $identity->getKey();
        }

        if (is_string($this->signal_session_id) && $this->signal_session_id !== '') {
            $session = $this->resolveSignalSessionForExperiment(
                $experiment,
                $this->signal_session_id,
                'Assignment signal session is not accessible in the current owner scope.',
            );

            if ($session->tracked_property_id !== $experiment->tracked_property_id) {
                throw new InvalidArgumentException('Assignment signal session must belong to the same tracked property as the experiment.');
            }

            $sessionIdentityId = is_scalar($session->signal_identity_id) && (string) $session->signal_identity_id !== ''
                ? (string) $session->signal_identity_id
                : null;

            if ($resolvedIdentityId !== null && $sessionIdentityId !== null && $sessionIdentityId !== $resolvedIdentityId) {
                throw new InvalidArgumentException('Assignment signal session must match the provided signal identity.');
            }
        }

        if ($experiment->owner_type !== $this->owner_type || (string) $experiment->owner_id !== (string) $this->owner_id) {
            throw new InvalidArgumentException('Assignment owner must match the parent experiment owner.');
        }
    }

    private function resolveSignalIdentityForExperiment(Experiment $experiment, string $id, string $message): SignalIdentity
    {
        $identity = $this->resolveSignalModelForExperiment(SignalIdentity::class, $experiment, $id, $message);

        if (! $identity instanceof SignalIdentity) {
            throw new InvalidArgumentException($message);
        }

        return $identity;
    }

    private function resolveSignalSessionForExperiment(Experiment $experiment, string $id, string $message): SignalSession
    {
        $session = $this->resolveSignalModelForExperiment(SignalSession::class, $experiment, $id, $message);

        if (! $session instanceof SignalSession) {
            throw new InvalidArgumentException($message);
        }

        return $session;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    private function resolveSignalModelForExperiment(string $modelClass, Experiment $experiment, string $id, string $message): Model
    {
        /** @var Builder<TModel> $query */
        $query = $modelClass::query();
        $modelOwnerScopingEnabled = $this->modelOwnerScopingEnabled($modelClass);

        if (Experiment::ownerScopeConfig()->enabled || $modelOwnerScopingEnabled) {
            $query = app(ScopeSignalQueryToOwner::class)->handle(
                $query,
                $this->signalOwnerForExperiment($experiment),
            );
        }

        $model = $query->whereKey($id)->first();

        if ($model instanceof Model) {
            return $model;
        }

        if (Experiment::ownerScopeConfig()->enabled || $modelOwnerScopingEnabled) {
            throw new AuthorizationException($message);
        }

        throw new InvalidArgumentException($message);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function modelOwnerScopingEnabled(string $modelClass): bool
    {
        if (! method_exists($modelClass, 'ownerScopeConfig')) {
            return false;
        }

        $config = $modelClass::ownerScopeConfig();

        return $config instanceof OwnerScopeConfig && $config->enabled;
    }

    private function signalOwnerForExperiment(Experiment $experiment): ?Model
    {
        if (Experiment::ownerScopeConfig()->enabled) {
            return OwnerContext::fromTypeAndId($experiment->owner_type, $experiment->owner_id);
        }

        $trackedProperty = $this->trackedPropertyForExperiment($experiment);

        if ($trackedProperty instanceof TrackedProperty) {
            return OwnerContext::fromTypeAndId($trackedProperty->owner_type, $trackedProperty->owner_id);
        }

        return null;
    }

    private function trackedPropertyForExperiment(Experiment $experiment): ?TrackedProperty
    {
        if ($experiment->relationLoaded('trackedProperty') && $experiment->trackedProperty instanceof TrackedProperty) {
            return $experiment->trackedProperty;
        }

        $trackedProperty = TrackedProperty::query()
            ->whereKey((string) $experiment->tracked_property_id)
            ->first();

        return $trackedProperty instanceof TrackedProperty ? $trackedProperty : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    private function resolveAccessibleModel(string $modelClass, string $id, string $message): Model
    {
        if (method_exists($modelClass, 'ownerScopeConfig')) {
            $config = $modelClass::ownerScopeConfig();

            if ($config->enabled) {
                /** @var TModel $model */
                $model = OwnerWriteGuard::findOrFailForOwner(
                    $modelClass,
                    $id,
                    OwnerContext::CURRENT,
                    $config->includeGlobal,
                    $message,
                );

                return $model;
            }
        }

        $model = $modelClass::query()->whereKey($id)->first();

        if (! $model instanceof Model) {
            throw new InvalidArgumentException($message);
        }

        return $model;
    }
}
