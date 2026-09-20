<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\DiscoveryNamespace\Tools;

use Martis\Tests\Fixtures\DiscoveryNamespace\Resources\DiscoveredWidgetResource;
use Martis\Tools\Tool;

/**
 * Tool counterpart of {@see DiscoveredWidgetResource}:
 * only discoverable when `tools_namespace` is derived (or set) correctly.
 */
class DiscoveredStatusTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Discovered Status', uriKey: 'discovered-status');
    }
}
