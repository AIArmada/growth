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
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function handle(Builder $query, ?Model $owner, bool $includeGlobal = false): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $query->getModel()::class;
        $ownerTypeColumn = 'owner_type';
        $ownerIdColumn = 'owner_id';

        if (method_exists($modelClass, 'ownerScopeConfig')) {
            /** @var OwnerScopeConfig $config */
            $config = $modelClass::ownerScopeConfig();
            $ownerTypeColumn = $config->ownerTypeColumn;
            $ownerIdColumn = $config->ownerIdColumn;
        }

        /** @var Builder<TModel> $scopedQuery */
        $scopedQuery = $query->withoutGlobalScope(OwnerScope::class);

        return OwnerQuery::applyToEloquentBuilder(
            $scopedQuery,
            $owner,
            $includeGlobal,
            $ownerTypeColumn,
            $ownerIdColumn,
        );
    }
}
