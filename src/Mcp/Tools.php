<?php

declare(strict_types=1);

namespace Martis\Mcp;

/**
 * MCP tool handlers exposed by `martis:mcp-serve`.
 *
 * Each public method becomes a tool through `php-mcp/server`'s
 * reflection-based registration. Docblocks describe the tool to the
 * agent; argument types and the `@param` lines drive the JSON schema.
 *
 * The class respects `MARTIS_MCP_ENABLED`: when set to `false`, every
 * tool returns a single short notice instead of running. The server
 * still publishes a clean handshake — the toggle is a runtime
 * behaviour gate, not a tools-list filter.
 */
class Tools
{
    public function __construct(private readonly DocLookup $docs) {}

    /**
     * List every Martis documentation page available with a one-line
     * description per page. Use this first to find the slug you need
     * before calling `martis_doc_read`.
     *
     * @return array{enabled: bool, pages: list<array{slug: string, one_liner: string}>}
     */
    public function listDocs(): array
    {
        if (! self::enabled()) {
            return self::disabledList();
        }

        return [
            'enabled' => true,
            'pages' => $this->docs->list(),
        ];
    }

    /**
     * Read one Martis documentation page in full. Pass the slug
     * returned by `martis_doc_list` (e.g. `gates`, `dashboards`,
     * `fields`). Returns the raw markdown content.
     *
     * @param  string  $slug  Page slug (e.g. `dashboards`, `gates`).
     * @return array{enabled: bool, slug?: string, content?: string, error?: string}
     */
    public function readDoc(string $slug): array
    {
        if (! self::enabled()) {
            return self::disabledRead();
        }
        $content = $this->docs->read($slug);
        if ($content === null) {
            return [
                'enabled' => true,
                'error' => "No documentation page found for slug `{$slug}`. Call martis_doc_list to see available slugs.",
            ];
        }

        return [
            'enabled' => true,
            'slug' => $slug,
            'content' => $content,
        ];
    }

    /**
     * Search the Martis documentation for a term and return the top
     * matches with snippets. Use this when you want to look up a
     * concept (e.g. `soft gates`, `BelongsToMany`, `cache layer`)
     * across all docs at once.
     *
     * @param  string  $query  Free-text query.
     * @param  int  $limit  Maximum number of matches to return (default 5).
     * @return array{enabled: bool, matches?: list<array{slug: string, score: int, snippet: string}>}
     */
    public function searchDocs(string $query, int $limit = 5): array
    {
        if (! self::enabled()) {
            return self::disabledList();
        }

        return [
            'enabled' => true,
            'matches' => $this->docs->search($query, $limit),
        ];
    }

    public static function enabled(): bool
    {
        $raw = getenv('MARTIS_MCP_ENABLED');
        if ($raw === false || $raw === '') {
            return true;
        }

        return ! in_array(strtolower((string) $raw), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @return array{enabled: false, message: string, pages: list<array{slug: string, one_liner: string}>, matches: list<array{slug: string, score: int, snippet: string}>}
     */
    private static function disabledList(): array
    {
        return [
            'enabled' => false,
            'message' => 'Martis MCP server is disabled (MARTIS_MCP_ENABLED=false). Set MARTIS_MCP_ENABLED=true in the host project .env to re-enable.',
            'pages' => [],
            'matches' => [],
        ];
    }

    /**
     * @return array{enabled: false, message: string}
     */
    private static function disabledRead(): array
    {
        return [
            'enabled' => false,
            'message' => 'Martis MCP server is disabled (MARTIS_MCP_ENABLED=false).',
        ];
    }
}
