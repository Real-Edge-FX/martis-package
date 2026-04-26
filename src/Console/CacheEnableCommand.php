<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Cache\MartisCache;

/**
 * `martis:cache:enable <type>` — re-enable a Martis cache type at
 * runtime, overriding the config when needed. Pair with
 * `martis:cache:disable` for ad-hoc troubleshooting.
 */
class CacheEnableCommand extends Command
{
    protected $signature = 'martis:cache:enable {type : Cache type to enable at runtime}';

    protected $description = 'Enable a Martis cache type at runtime (persisted, no redeploy).';

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

        $cache->enable($type);
        $this->info(sprintf('Martis cache "%s" enabled at runtime.', $type));

        return self::SUCCESS;
    }
}
