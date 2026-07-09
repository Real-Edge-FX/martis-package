<?php

namespace Martis\Tests\StaticAnalysis;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Layout\Panel;
use Martis\Layout\Section;
use Martis\Resource;

/**
 * Static-analysis fixture, NOT a runtime test. It exists solely so PHPStan
 * (which analyses this directory) proves that a Resource may return layout
 * wrappers (Section/Panel = LayoutContract) from fields()/detailSidebar() —
 * the documented grid-layout feature. Before the field methods were typed
 * `list<FieldContract|LayoutContract>`, this file failed PHPStan level 8 with
 * `return.type`. If someone narrows the type back to `list<FieldContract>`,
 * this fixture turns CI red again, guarding the fix against regression.
 */
class GridResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    /** {@inheritdoc} */
    public function fields(Request $request): array
    {
        return [
            Section::make('Identity', [
                Text::make('name'),
                Text::make('email'),
            ])->columns(2),
            Text::make('created_at'),
        ];
    }

    /** {@inheritdoc} */
    public function fieldsForCreate(Request $request): array
    {
        // Guards the fieldsFor*() context methods (they inherit the widened
        // return type from the interface).
        return [
            Section::make('New', [Text::make('name')])->columns(2),
        ];
    }

    /** {@inheritdoc} */
    public function detailSidebar(Request $request): array
    {
        return [
            Panel::make('Meta', [
                Text::make('updated_at'),
            ]),
        ];
    }
}
