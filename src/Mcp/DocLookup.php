<?php

declare(strict_types=1);

namespace Martis\Mcp;

/**
 * Reads the canonical Martis docs (`vendor/martis/martis/docs/*.md`)
 * and provides list / read / search helpers consumed by the MCP
 * tools. The server is read-only; mutations are out of scope.
 */
class DocLookup
{
    public function __construct(private readonly string $docsDir) {}

    public static function package(): self
    {
        return new self(realpath(__DIR__.'/../../docs') ?: __DIR__.'/../../docs');
    }

    /**
     * @return list<array{slug: string, one_liner: string}>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->files() as $path) {
            $slug = basename($path, '.md');
            $rows[] = [
                'slug' => $slug,
                'one_liner' => $this->oneLiner($path),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $rows;
    }

    public function read(string $slug): ?string
    {
        $slug = $this->safeSlug($slug);
        if ($slug === null) {
            return null;
        }
        $path = $this->docsDir.'/'.$slug.'.md';
        if (! is_file($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param  int  $limit  Maximum number of results to return (default 5). Values <= 0 are clamped to 1.
     * @return list<array{slug: string, score: int, snippet: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $needle = mb_strtolower($query);
        $rows = [];
        foreach ($this->files() as $path) {
            $haystack = (string) file_get_contents($path);
            $lower = mb_strtolower($haystack);
            $score = substr_count($lower, $needle);
            if ($score === 0) {
                continue;
            }
            $rows[] = [
                'slug' => basename($path, '.md'),
                'score' => $score,
                'snippet' => $this->snippet($haystack, $query),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        if (! is_dir($this->docsDir)) {
            return [];
        }
        $files = glob($this->docsDir.'/*.md') ?: [];
        sort($files);

        return $files;
    }

    private function oneLiner(string $path): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $trim = trim($line);
                if ($trim === '' || str_starts_with($trim, '#')) {
                    continue;
                }
                if (mb_strlen($trim) > 160) {
                    return mb_substr($trim, 0, 157).'...';
                }

                return $trim;
            }
        } finally {
            fclose($handle);
        }

        return '';
    }

    private function snippet(string $haystack, string $query): string
    {
        $position = mb_stripos($haystack, $query);
        if ($position === false) {
            return mb_substr($haystack, 0, 160);
        }
        $start = max(0, $position - 60);
        $snippet = trim(mb_substr($haystack, $start, 240));

        return ($start > 0 ? '...' : '').$snippet.(mb_strlen($haystack) > $start + 240 ? '...' : '');
    }

    private function safeSlug(string $slug): ?string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $slug) !== 1) {
            return null;
        }

        return $slug;
    }
}
