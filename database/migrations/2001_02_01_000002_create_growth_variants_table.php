<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('growth.database.tables.variants', 'growth_variants'), function (Blueprint $table): void {
            $jsonColumnType = config('growth.database.json_column_type', commerce_json_column_type('growth', 'jsonb'));

            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id');
            $table->nullableUuidMorphs('owner');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('traffic_percentage')->default(50);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_control')->default(false);
            $table->string('status')->default('draft');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->{$jsonColumnType}('settings')->nullable();
            $table->timestampsTz();

            $table->unique(['experiment_id', 'code']);
            $table->index(['experiment_id', 'status']);
            $table->index(['experiment_id', 'position']);
        });
    }
};
