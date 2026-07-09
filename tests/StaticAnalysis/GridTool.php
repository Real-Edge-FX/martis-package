<?php

namespace Martis\Tests\StaticAnalysis;

use Illuminate\Http\Request;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Fields\Text;
use Martis\Layout\Section;
use Martis\Tools\Tool;

/**
 * Static-analysis guard for a Tool's fields() via the ProvidesFields
 * interface + ProvidesToolFields trait. Overriding fields() to return a
 * Section must pass PHPStan level 8; narrowing the interface or the trait
 * back to list<FieldContract> makes this override a covariance violation.
 */
class GridTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function fields(Request $request): array
    {
        return [
            Section::make('Tool Grid', [
                Text::make('name'),
            ])->columns(2),
        ];
    }
}
