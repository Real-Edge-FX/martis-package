<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Fields\Text;
use Martis\Tools\Tool;

it('defaults to an empty fields array and satisfies ProvidesFields', function () {
    $tool = new class('Charts', 'charts') extends Tool implements ProvidesFields
    {
        use ProvidesToolFields;
    };

    expect($tool)->toBeInstanceOf(ProvidesFields::class)
        ->and($tool->fields(new Request))->toBe([]);
});

it('allows a Tool to override fields() and return its own builders', function () {
    $tool = new class('Charts', 'charts') extends Tool implements ProvidesFields
    {
        use ProvidesToolFields;

        public function fields(Request $request): array
        {
            return [
                Text::make('Title'),
            ];
        }
    };

    $fields = $tool->fields(new Request);

    expect($fields)->toHaveCount(1)
        ->and($fields[0])->toBeInstanceOf(Text::class);
});
