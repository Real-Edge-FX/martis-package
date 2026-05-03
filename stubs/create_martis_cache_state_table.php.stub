<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Martis cache state — operational metadata for the per-layer cache
 * subsystem (`metrics`, `navigation`, `dashboards`, `schema`, plus any
 * custom layer registered via `MartisCache::extend()`).
 *
 * Why a dedicated table instead of stashing this in the cache itself?
 * The version counter, `cleared_at` timestamp, and runtime override
 * flag are the operator's source of truth for "is this layer caching
 * right now, and when did I last invalidate it?". Storing them in the
 * same Redis / file / memcached store that they invalidate is a trap:
 *
 *   • `php artisan cache:clear` (deploy scripts love it) wipes them.
 *   • `redis-cli FLUSHDB` wipes them.
 *   • `maxmemory-policy: allkeys-lru` evicts them under pressure.
 *   • Container restarts without a persistent volume wipe them.
 *
 * Putting this metadata in a dedicated table makes it survive every
 * one of those operator habits. Cache entries themselves still live
 * in `Cache::store()` — only the operational state is DB-backed.
 *
 * One row per cache layer. Defaults match the historical behaviour so
 * a fresh install (zero rows) reads `version=1`, `cleared_at=null`,
 * `override=null` (= inherit config).
 *
 * Idempotent — safe to run on already-migrated apps (no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('martis_cache_state')) {
            return;
        }

        Schema::create('martis_cache_state', function (Blueprint $table) {
            // Layer name (`metrics`, `navigation`, `dashboards`,
            // `schema`, or a name registered via MartisCache::extend()).
            $table->string('type')->primary();

            // Per-layer version counter. Bumping it makes every entry
            // keyed `martis:cache:{type}:v{N}:...` orphaned at once
            // (atomic O(1) invalidation; see MartisCache docblock).
            $table->unsignedInteger('version')->default(1);

            // Timestamp of the last `clear()` call. Surfaced in the
            // admin UI as the "Cleared at" column.
            $table->timestamp('cleared_at')->nullable();

            // Persistent runtime override:
            //   • null  → inherit the config flag (default).
            //   • true  → forced ON  (beats `config_enabled = false`).
            //   • false → forced OFF (beats `config_enabled = true`).
            // Set / cleared via `martis:cache:enable|disable|reset` or
            // the admin UI buttons. Survives Redis flushes because
            // it lives here, not in Cache::store().
            $table->boolean('override')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('martis_cache_state');
    }
};
