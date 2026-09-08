<?php

declare(strict_types=1);

namespace AIArmada\Growth\Actions;

use AIArmada\CommerceSupport\Support\OwnerQuery;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Support\OwnerScopeConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ScopeSignalQueryToOwner
{
    /**
     * Canonical owner scoping lives on HasOwner::forOwner(). Models whose owner
     * mode is disabled still need explicit tuple filtering when a related
     * owner-scoped package remains enabled.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function handle(Builder $query, ?Model $owner, bool $includeGlobal = false): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $query->getModel()::class;

        if (method_exists($modelClass, 'ownerScopeConfig')) {
            /** @var OwnerScopeConfig $config */
            $config = $modelClass::ownerScopeConfig();

            if ($config->enabled) {
                $query = $query->withoutGlobalScope(OwnerScope::class);
            }

            return OwnerQuery::applyToEloquentBuilder(
                $query,
                $owner,
                $includeGlobal,
                $config->ownerTypeColumn,
                $config->ownerIdColumn,
            );
        }

        /** @var Builder<TModel> $query */
        return OwnerQuery::applyToEloquentBuilder($query, $owner, $includeGlobal);
    }
}
