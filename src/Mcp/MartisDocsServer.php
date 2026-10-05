<?php

declare(strict_types=1);

namespace Martis\Mcp;

use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use Martis\Mcp\Tools\DocListTool;
use Martis\Mcp\Tools\DocReadTool;
use Martis\Mcp\Tools\DocSearchTool;

/**
 * The Martis docs MCP server. Over stdio an agent spawns
 * `php artisan mcp:start martis-docs`; over HTTP it is the route that
 * `McpRoutes` registers when `MARTIS_MCP_TRANSPORT=http`.
 */
class MartisDocsServer extends Server
{
    /** The handle `Mcp::local()` registers and `mcp:start` takes. */
    public const HANDLE = 'martis-docs';

    protected string $name = 'Martis Docs';

    protected string $instructions = 'Read the Martis documentation: martis_doc_list returns every page with its topic, martis_doc_search finds the pages for a question, martis_doc_read returns one page in full.';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        DocListTool::class,
        DocReadTool::class,
        DocSearchTool::class,
    ];

    protected function boot(): void
    {
        $this->version = self::packageVersion();
    }

    /** The installed martis/martis version, without a leading `v`. */
    public static function packageVersion(): string
    {
        return ltrim((string) InstalledVersions::getPrettyVersion('martis/martis'), 'v');
    }
}
