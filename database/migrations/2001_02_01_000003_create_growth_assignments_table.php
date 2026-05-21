<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('growth.database.tables.assignments', 'growth_assignments'), function (Blueprint $table): void {
            $jsonColumnType = config('growth.database.json_column_type', commerce_json_column_type('growth', 'json'));

            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id');
            $table->foreignUuid('variant_id');
            $table->foreignUuid('signal_identity_id')->nullable();
            $table->foreignUuid('signal_session_id')->nullable();
            $table->nullableUuidMorphs('owner');
            $table->string('subject_key');
            $table->unsignedInteger('bucket')->default(0);
            $table->{$jsonColumnType}('metadata')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('first_exposed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['experiment_id', 'subject_key']);
            $table->unique(['experiment_id', 'signal_identity_id']);
            $table->unique(['experiment_id', 'signal_session_id']);
            $table->index(['experiment_id', 'variant_id']);
        });
    }
};
