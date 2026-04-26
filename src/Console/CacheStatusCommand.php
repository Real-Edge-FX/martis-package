<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Cache\MartisCache;

/**
 * `martis:cache:status` — table view of every Martis cache type and its
 * current effective state (enabled, ttl, runtime override, version,
 * cleared_at).
 */
class CacheStatusCommand extends Command
{
    protected $signature = 'martis:cache:status';

    protected $description = 'Show the current state of every Martis cache type.';

    public function handle(MartisCache $cache): int
    {
        $rows = array_map(static function (array $row): array {
            return [
                $row['type'],
                $row['enabled'] ? '<info>yes</info>' : '<comment>no</comment>',
                $row['ttl'] === null ? 'forever' : $row['ttl'].'m',
                $row['config_enabled'] ? 'yes' : 'no',
                match ($row['runtime_override']) {
                    true => '<info>enabled</info>',
                    false => '<comment>disabled</comment>',
                    default => '—',
                },
                'v'.$row['version'],
                $row['cleared_at'] ?? '—',
            ];
        }, $cache->status());

        $this->newLine();
        $this->line(' Master switch: '.($cache->masterEnabled() ? '<info>ON</info>' : '<comment>OFF</comment>'));
        $this->newLine();

        $this->table(
            ['Type', 'Effective', 'TTL', 'Config', 'Runtime', 'Version', 'Cleared at'],
            $rows,
        );

        return self::SUCCESS;
    }
}
