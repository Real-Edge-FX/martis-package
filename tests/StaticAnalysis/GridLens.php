<?php

namespace Martis\Tests\StaticAnalysis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Http\Requests\LensRequest;
use Martis\Layout\Section;
use Martis\Lenses\Lens;

/**
 * Static-analysis guard for Lens::fields() (LensController flattens layout
 * wrappers just like a Resource). A Lens returning a Section must pass
 * PHPStan level 8; narrowing Lens::fields() back to list<FieldContract>
 * turns this red.
 */
class GridLens extends Lens
{
    /**
     * @param  LensRequest<Model>  $request
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    /** {@inheritdoc} */
    public function fields(Request $request): array
    {
        return [
            Section::make('Lens Grid', [
                Text::make('name'),
            ])->columns(2),
        ];
    }
}
