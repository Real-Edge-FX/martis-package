<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Cache\MartisCache;

/**
 * `martis:cache:clear [type|all]` — invalidate one cache type (or every
 * type when `all` / no argument). Implemented as a per-type version
 * bump, so it's O(1) and works on any cache backend.
 */
class CacheClearCommand extends Command
{
    protected $signature = 'martis:cache:clear {type? : Cache type to clear, or "all" / omitted for every type}';

    protected $description = 'Invalidate one or every Martis cache type.';

    public function handle(MartisCache $cache): int
    {
        $type = $this->argument('type');

        if ($type === null || $type === 'all') {
            $cache->clear();
            $this->info('All Martis caches cleared.');

            return self::SUCCESS;
        }

        if (! in_array($type, MartisCache::types(), true)) {
            $this->error(sprintf(
                'Unknown cache type "%s". Known types: %s.',
                $type,
                implode(', ', MartisCache::types()),
            ));

            return self::INVALID;
        }

        $cache->clear($type);
        $this->info(sprintf('Martis cache "%s" cleared.', $type));

        return self::SUCCESS;
    }
}
