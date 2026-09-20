<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\DiscoveryNamespace\Resources;

use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\Tests\Fixtures\DiscoveryNamespace\DiscoveredWidget;

/**
 * Autoloaded through the package's own PSR-4 map (`Martis\Tests\` →
 * `tests/`), so the service provider can only find it when it derives
 * the namespace of `tests/Fixtures/DiscoveryNamespace/Resources` from
 * that map (or is told it explicitly). Its uriKey is `discovered-widgets`.
 */
class DiscoveredWidgetResource extends Resource
{
    public static function model(): string
    {
        return DiscoveredWidget::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}
