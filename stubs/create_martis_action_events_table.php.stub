<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Martis action events audit log.
 *
 * Idempotent — safe to run on:
 *   • fresh apps           (creates `martis_action_events` from scratch)
 *   • upgraded apps        (renames legacy `action_events` → `martis_action_events`)
 *   • already-migrated apps (no-op)
 *
 * The `martis_` prefix keeps every Martis-owned table in one namespace so
 * it never collides with an app's own `action_events` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('martis_action_events')) {
            return;
        }

        if (Schema::hasTable('action_events')) {
            Schema::rename('action_events', 'martis_action_events');

            return;
        }

        Schema::create('martis_action_events', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('actionable_type')->nullable();
            $table->unsignedBigInteger('actionable_id')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('fields');
            $table->string('status', 25)->default('running');
            $table->text('exception');
            $table->text('original');
            $table->text('changes');
            $table->timestamps();

            $table->index(['actionable_type', 'actionable_id']);
            $table->index(['batch_id', 'model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('martis_action_events');
    }
};
