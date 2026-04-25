<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Martis user preferences.
 *
 * Persists per-user UI preferences (theme, accent, density, locale,
 * reduced-motion flag, optional brand_color hex) so settings travel
 * across devices and sessions. The preferences resolver falls back to
 * `config('martis.preferences.defaults')` silently when the table does
 * not exist — apps that skip this migration degrade to a stateless
 * experience without errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('martis_user_preferences')) {
            return;
        }

        Schema::create('martis_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('theme', 16)->default('dark');     // ThemeMode enum
            $table->string('accent', 16)->default('martis');  // AccentColor enum
            $table->string('brand_color', 9)->nullable();     // #RRGGBB or #RRGGBBAA
            $table->string('density', 16)->default('comfortable'); // UiDensity enum
            $table->string('locale', 10)->default('en');
            $table->boolean('reduced_motion')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('martis_user_preferences');
    }
};
