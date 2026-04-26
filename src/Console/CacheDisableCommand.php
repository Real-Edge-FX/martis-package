<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Cache\MartisCache;

/**
 * `martis:cache:disable <type>` — runtime kill-switch. Persists in the
 * cache store so it survives restarts. Useful in production for
 * troubleshooting without flipping env vars or redeploying.
 */
class CacheDisableCommand extends Command
{
    protected $signature = 'martis:cache:disable {type : Cache type to disable at runtime}';

    protected $description = 'Disable a Martis cache type at runtime (persisted, no redeploy).';

    public function handle(MartisCache $cache): int
    {
        $type = (string) $this->argument('type');

        if (! in_array($type, MartisCache::types(), true)) {
            $this->error(sprintf(
                'Unknown cache type "%s". Known types: %s.',
                $type,
                implode(', ', MartisCache::types()),
            ));

            return self::INVALID;
        }

        $cache->disable($type);
        $this->info(sprintf('Martis cache "%s" disabled at runtime.', $type));

        return self::SUCCESS;
    }
}
