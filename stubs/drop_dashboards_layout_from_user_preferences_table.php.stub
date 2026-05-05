<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.10.5: drop the `dashboards_layout` column.
 *
 * v1.10.4 introduced a per-user `dashboards_layout` preference (tabs vs
 * sidebar) and added the column. v1.10.5 reverted that decision in
 * favour of declarative per-dashboard nesting via `Dashboard::under()`,
 * which makes the column dead weight. This migration drops it.
 *
 * Idempotent: skipped when the column is already absent (eg. fresh
 * installs that never ran the v1.10.4 column-add).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('martis_user_preferences')) {
            return;
        }

        if (! Schema::hasColumn('martis_user_preferences', 'dashboards_layout')) {
            return;
        }

        Schema::table('martis_user_preferences', function (Blueprint $table) {
            $table->dropColumn('dashboards_layout');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('martis_user_preferences')) {
            return;
        }

        if (Schema::hasColumn('martis_user_preferences', 'dashboards_layout')) {
            return;
        }

        Schema::table('martis_user_preferences', function (Blueprint $table) {
            $table->string('dashboards_layout', 16)->default('tabs')->after('reduced_motion');
        });
    }
};
