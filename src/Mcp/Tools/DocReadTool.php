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

#[Name('martis_doc_read')]
#[IsReadOnly]
class DocReadTool extends Tool
{
    protected string $title = 'Read a Martis doc';

    protected string $description = 'Read one Martis documentation page in full. Pass the slug returned by `martis_doc_list` (e.g. `gates`, `dashboards`, `fields`). Returns the raw markdown content.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Page slug (e.g. `dashboards`, `gates`).')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $input = $request->validate(['slug' => ['required', 'string']]);

        return Response::json(Tools::package()->readDoc($input['slug']));
    }
}
