<?php

declare(strict_types=1);

namespace Martis\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Names the migrations a Martis command publishes, so that they run in the
 * order they are published.
 *
 * Laravel runs pending migrations sorted by file name. Each name returned
 * here is later than the current second, than every migration already in
 * the directory (Spatie dates its `create_permission_tables` one second
 * ahead when its provider boots) and than the name returned before, so a
 * command that publishes several files within one second keeps their order.
 */
final class MigrationTimestamps
{
    private const FORMAT = 'Y_m_d_His';

    private ?CarbonImmutable $last = null;

    public function __construct(private readonly string $migrationsPath) {}

    /**
     * The file name, `{Y_m_d_His}_{$migrationName}.php`, of the next
     * migration published into the directory.
     */
    public function filename(string $migrationName): string
    {
        $next = now()->toImmutable()->startOfSecond();

        foreach ([$this->newestOnDisk(), $this->last] as $earlier) {
            if ($earlier !== null && $earlier->addSecond()->greaterThan($next)) {
                $next = $earlier->addSecond();
            }
        }

        $this->last = $next;

        return $next->format(self::FORMAT)."_{$migrationName}.php";
    }

    /**
     * The newest timestamp among the migrations directly in the directory,
     * read again on every call so that a file another publisher wrote since
     * counts. Files without a leading timestamp are ignored.
     */
    private function newestOnDisk(): ?CarbonImmutable
    {
        $newest = null;

        foreach (glob($this->migrationsPath.'/*.php') ?: [] as $file) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match) === 1
                && ($newest === null || strcmp($match[1], $newest) > 0)) {
                $newest = $match[1];
            }
        }

        if ($newest === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $newest);

        return $date === false ? null : CarbonImmutable::instance($date);
    }
}
