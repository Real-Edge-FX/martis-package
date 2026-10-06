<?php

declare(strict_types=1);

namespace Martis\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Martis\Mcp\Tools;

#[Name('martis_doc_search')]
#[IsReadOnly]
class DocSearchTool extends Tool
{
    protected string $title = 'Search the Martis docs';

    protected string $description = 'Search the Martis documentation for a term and return the top matches with snippets. Use this when you want to look up a concept (e.g. `soft gates`, `BelongsToMany`, `cache layer`) across all docs at once.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Free-text query.')->required(),
            'limit' => $schema->integer()->description('Maximum number of matches to return (default 5).')->default(5),
        ];
    }

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'query' => ['required', 'string'],
            'limit' => ['sometimes', 'integer'],
        ]);

        return Response::json(Tools::package()->searchDocs($input['query'], (int) ($input['limit'] ?? 5)));
    }
}
