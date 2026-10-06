<?php

declare(strict_types=1);

namespace Martis\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Martis\Mcp\Tools;

#[Name('martis_doc_list')]
#[IsReadOnly]
class DocListTool extends Tool
{
    protected string $title = 'List the Martis docs';

    protected string $description = 'List every Martis documentation page available with a one-line description per page. Use this first to find the slug you need before calling `martis_doc_read`.';

    public function handle(): Response
    {
        return Response::json(Tools::package()->listDocs());
    }
}
