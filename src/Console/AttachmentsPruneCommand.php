<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Martis\ResourceRegistry;
use Throwable;

/**
 * `martis:attachments:prune`: delete the files uploaded through the rich
 * text editors (Trix, Markdown) that no record references any more.
 *
 * An upload is stored under `martis-attachments/` as `{40 random characters}.{ext}`
 * and its URL is embedded in the content of the field that asked for it.
 * There is no table of uploads, so a file the editor never saved (a closed
 * form, a removed image) stays on the disk. The command finds the references
 * by reading the text and JSON columns of the table of every registered
 * resource's model (Repeater rows and every other column included, soft
 * deleted records too) for the stored file names, and deletes the files of
 * `martis-attachments/` that none of them holds and that were written more
 * than `--hours` ago (24 by default, so an upload from a form still open is
 * never taken). A file referenced from a table no registered resource
 * writes to is not seen: run it with `--dry-run` first. It refuses (and
 * deletes nothing) when no registered resource has a table it can scan.
 */
class AttachmentsPruneCommand extends Command
{
    /** The directory the upload endpoint stores the files under. */
    private const DIRECTORY = 'martis-attachments';

    protected $signature = 'martis:attachments:prune
                            {--hours=24 : Only delete files written more than this many hours ago}
                            {--dry-run : List what would be deleted without deleting it}
                            {--disk=* : Disk to sweep (repeatable); default: the panel\'s storage disk}';

    protected $description = 'Delete the rich text attachments (Trix, Markdown uploads) no record references any more.';

    public function handle(ResourceRegistry $registry): int
    {
        $hours = (int) $this->option('hours');
        if ($hours < 1) {
            $this->components->error('--hours must be at least 1: a file written within the hour may belong to a form still open.');

            return self::FAILURE;
        }

        $disks = $this->disks();

        // A table that cannot be read cannot be ruled out: stop before
        // deleting a file it may reference.
        try {
            [$referenced, $scanned] = $this->referencedNames($registry);
        } catch (Throwable $e) {
            $this->components->error('Could not read the records that reference the attachments, nothing was deleted: '.$e->getMessage());

            return self::FAILURE;
        }

        // With nothing scanned every file looks unreferenced: a console that
        // booted without the app's resources (another `resources_path`, a
        // discovery that failed, resources registered conditionally) would
        // delete every attachment. Refuse rather than guess.
        if ($scanned === 0) {
            $this->components->error('No registered resource has a table with a text or JSON column to scan, so every file would look unreferenced. Nothing was deleted. Check that the console registers the panel\'s resources (martis.resources_path) and run it with --dry-run.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');

        $found = 0;
        $bytes = 0;

        foreach ($disks as $name) {
            try {
                $disk = Storage::disk($name);
                $files = $disk->files(self::DIRECTORY);
            } catch (Throwable $e) {
                $this->components->error("Could not read disk [{$name}]: ".$e->getMessage());

                return self::FAILURE;
            }

            foreach ($files as $path) {
                if (! $this->isOrphan($disk, $path, $cutoff, $referenced)) {
                    continue;
                }

                $size = $this->sizeOf($disk, $path);
                $found++;
                $bytes += $size;

                if ($dryRun) {
                    $this->line("[{$name}] would delete {$path} (".$this->humanSize($size).')');

                    continue;
                }

                $disk->delete($path);
            }
        }

        $this->components->info(sprintf(
            '%s %d unreferenced attachment%s (%s) on %s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $found,
            $found === 1 ? '' : 's',
            $this->humanSize($bytes),
            implode(', ', array_map(static fn (string $disk): string => "[{$disk}]", $disks)),
        ));

        return self::SUCCESS;
    }

    /**
     * The disks to sweep: `--disk`, or the panel's storage disk.
     *
     * @return list<string>
     */
    private function disks(): array
    {
        /** @var list<string> $given */
        $given = array_values(array_filter((array) $this->option('disk'), static fn (mixed $disk): bool => is_string($disk) && $disk !== ''));

        if ($given !== []) {
            return array_values(array_unique($given));
        }

        $default = config('martis.storage.disk', 'public');

        return [is_string($default) && $default !== '' ? $default : 'public'];
    }

    /**
     * Whether `$path` is an upload nothing references and old enough to go.
     *
     * @param  array<string, true>  $referenced  The file stems the records hold, as keys.
     */
    private function isOrphan(Filesystem $disk, string $path, int $cutoff, array $referenced): bool
    {
        $stem = pathinfo($path, PATHINFO_FILENAME);

        // Only what the endpoint wrote: 40 alphanumeric characters and an
        // extension. Anything else in the directory is not ours to delete.
        if (preg_match('/^[A-Za-z0-9]{40}$/', $stem) !== 1 || pathinfo($path, PATHINFO_EXTENSION) === '') {
            return false;
        }

        if (isset($referenced[$stem])) {
            return false;
        }

        try {
            return $disk->lastModified($path) < $cutoff;
        } catch (Throwable) {
            return false;
        }
    }

    /** A byte count as B, KB, MB or GB (no intl extension needed). */
    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($size, 1, '.', '')).' '.$units[$unit];
    }

    private function sizeOf(Filesystem $disk, string $path): int
    {
        try {
            return $disk->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The file stems (the 40 random characters) the records hold: every
     * `{40 alphanumeric characters}.{extension}` in the text and JSON
     * columns of the table of each registered resource's model. The URL
     * and the JSON escaping around a name do not matter, only the name.
     *
     * Returns the names and how many tables were scanned: none means the
     * names prove nothing (see `handle()`).
     *
     * @return array{array<string, true>, int}
     */
    private function referencedNames(ResourceRegistry $registry): array
    {
        $names = [];
        $seen = [];
        $scanned = 0;

        foreach ($registry->list() as $resourceClass) {
            $modelClass = $resourceClass::model();
            /** @var Model $model */
            $model = new $modelClass;
            $connection = $model->getConnection();
            $table = $model->getTable();

            $key = ($model->getConnectionName() ?? '').'|'.$table;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $columns = $this->textColumns($connection->getSchemaBuilder()->getColumns($table));
            if ($columns === []) {
                continue;
            }

            $scanned++;

            foreach ($connection->table($table)->select($columns)->cursor() as $row) {
                foreach ((array) $row as $value) {
                    if (is_string($value) && preg_match_all('/([A-Za-z0-9]{40})\.[A-Za-z0-9]{1,10}/', $value, $matches) > 0) {
                        foreach ($matches[1] as $stem) {
                            $names[$stem] = true;
                        }
                    }
                }
            }
        }

        return [$names, $scanned];
    }

    /**
     * The columns that can hold text or JSON.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<string>
     */
    private function textColumns(array $columns): array
    {
        $names = [];

        foreach ($columns as $column) {
            $type = Str::lower((string) ($column['type_name'] ?? $column['type'] ?? ''));

            if (Str::contains($type, ['char', 'text', 'json', 'clob', 'string'])) {
                $names[] = (string) $column['name'];
            }
        }

        return $names;
    }
}
