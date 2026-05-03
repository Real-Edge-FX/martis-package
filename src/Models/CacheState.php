<?php

declare(strict_types=1);

namespace Martis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational metadata for one cache layer (`metrics`, `navigation`,
 * `dashboards`, `schema`, or any custom layer registered via
 * `MartisCache::extend()`).
 *
 * One row per layer. Lives in its own table so the version counter,
 * `cleared_at` timestamp and runtime override flag survive every
 * cache-backend reset (`Cache::flush()`, `redis-cli FLUSHDB`,
 * container restart, LRU eviction). The cache entries themselves
 * still live in `Cache::store()` — only the operational state is
 * DB-backed.
 *
 * Defaults match the historical "no row" behaviour:
 *   • `version`     = 1
 *   • `cleared_at`  = null
 *   • `override`    = null  (= inherit `config('martis.cache.{type}.enabled')`)
 *
 * Columns:
 *   - `type`       : layer name (primary key, string).
 *   - `version`    : per-layer version counter; bumping invalidates
 *                    every entry keyed `martis:cache:{type}:v{N}:...`.
 *   - `cleared_at` : timestamp of the last `clear()` call.
 *   - `override`   : `null` = inherit config / `true` = forced ON /
 *                    `false` = forced OFF.
 *
 * @property string $type
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $cleared_at
 * @property bool|null $override
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CacheState extends Model
{
    protected $table = 'martis_cache_state';

    protected $primaryKey = 'type';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['type', 'version', 'cleared_at', 'override'];

    protected $casts = [
        'version' => 'integer',
        'cleared_at' => 'datetime',
        'override' => 'boolean',
    ];
}
