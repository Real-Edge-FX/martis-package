<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Cache\MartisCache;

/**
 * `martis:cache:prune`: delete the cache entries earlier Martis versions and
 * cleared counters left behind, on the stores that keep them (database,
 * file). Redis and memcached expire keys on their own.
 */
class CachePruneCommand extends Command
{
    protected $signature = 'martis:cache:prune';

    protected $description = 'Delete the Martis cache entries earlier versions and clears left behind (database and file stores).';

    public function handle(MartisCache $cache): int
    {
        $result = $cache->prune();

        if (! $result['supported']) {
            $this->info(sprintf('The %s cache store drops expired keys on its own: nothing to prune.', $result['driver']));

            return self::SUCCESS;
        }

        $this->info(sprintf('Pruned %d stale entr%s from the %s cache store.', $result['deleted'], $result['deleted'] === 1 ? 'y' : 'ies', $result['driver']));

        return self::SUCCESS;
    }
}
