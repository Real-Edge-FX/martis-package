<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Idempotent line-level edit of `.env` and `.env.example` style files.
 *
 * `set()` adds the key with the requested value if absent; if present
 * with any value, leaves it alone (we do not clobber operator-set
 * values). `remove()` strips the key and any directly-attached
 * comment lines added by Martis.
 */
class EnvFilePatcher
{
    public const COMMENT_PREFIX = '# Martis MCP server toggle';

    public function __construct(private readonly string $basePath) {}

    public function set(string $relativeFile, string $key, string $value, ?string $comment = null): bool
    {
        $absolute = $this->basePath.'/'.$relativeFile;
        if (! file_exists($absolute)) {
            return false;
        }

        $contents = (string) file_get_contents($absolute);
        if ($this->hasKey($contents, $key)) {
            return false;
        }

        $tail = '';
        if (! str_ends_with($contents, "\n")) {
            $tail = "\n";
        }

        $block = $tail."\n";
        if ($comment !== null) {
            $block .= '# '.$comment."\n";
        }
        $block .= $key.'='.$value."\n";

        file_put_contents($absolute, $contents.$block);

        return true;
    }

    public function remove(string $relativeFile, string $key): bool
    {
        $absolute = $this->basePath.'/'.$relativeFile;
        if (! file_exists($absolute)) {
            return false;
        }

        $contents = (string) file_get_contents($absolute);
        if (! $this->hasKey($contents, $key)) {
            return false;
        }

        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $kept = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'=/', $line)) {
                if ($i > 0 && isset($kept[count($kept) - 1]) && str_starts_with(trim($kept[count($kept) - 1]), '#')) {
                    array_pop($kept);
                }

                continue;
            }
            $kept[] = $line;
        }

        file_put_contents($absolute, implode("\n", $kept));

        return true;
    }

    /**
     * Like set(), but writes the key as a commented-out placeholder
     * (line prefixed with `#`). Useful for documenting available knobs
     * without setting them.
     */
    public function setCommented(string $relativeFile, string $key, string $value, ?string $comment = null): bool
    {
        $absolute = $this->basePath.'/'.$relativeFile;
        if (! file_exists($absolute)) {
            return false;
        }

        $contents = (string) file_get_contents($absolute);
        if ($this->hasKey($contents, $key)) {
            return false; // Already present (commented or not) — don't double-write.
        }

        $tail = str_ends_with($contents, "\n") ? '' : "\n";
        $block = $tail."\n";
        if ($comment !== null) {
            $block .= '# '.$comment."\n";
        }
        $block .= '# '.$key.'='.$value."\n";

        file_put_contents($absolute, $contents.$block);

        return true;
    }

    private function hasKey(string $contents, string $key): bool
    {
        return (bool) preg_match('/^\s*#?\s*'.preg_quote($key, '/').'=/m', $contents);
    }
}
