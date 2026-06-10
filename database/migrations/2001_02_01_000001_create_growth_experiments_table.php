<?php

declare(strict_types=1);

use AIArmada\Growth\Enums\ExperimentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('growth.database.tables.experiments', 'growth_experiments'), function (Blueprint $table): void {
            $jsonColumnType = config('growth.database.json_column_type', commerce_json_column_type('growth', 'jsonb'));

            $table->uuid('id')->primary();
            $table->foreignUuid('tracked_property_id');
            $table->nullableUuidMorphs('owner');
            $table->string('owner_scope')->default('global');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('module_type')->default(config('growth.defaults.module_type', 'ab_test'));
            $table->string('status')->default(ExperimentStatus::Draft->value);
            $table->string('goal_event_name')->default(config('growth.integrations.signals.purchase_event_name', 'order.paid'));
            $table->string('goal_event_category')->default('conversion');
            $table->string('winner_metric')->default(config('growth.defaults.winner_metric', 'revenue_per_visitor'));
            $table->{$jsonColumnType}('audience')->nullable();
            $table->{$jsonColumnType}('settings')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('concluded_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['owner_scope', 'slug']);
            $table->index(['tracked_property_id', 'status']);
            $table->index(['tracked_property_id', 'module_type']);
        });
    }
};
