<?php

use Illuminate\Http\Request;
use Martis\Fields\Select;

// ---------------------------------------------------------------------------
// Options in Nova's order: [value => label] (v2.0.0)
// ---------------------------------------------------------------------------

it('reads options as [value => label], the order Nova uses', function () {
    $field = Select::make('status')->options([
        'active' => 'Active',
        'inactive' => 'Inactive',
    ]);

    expect($field->getOptions())->toBe([
        ['label' => 'Active', 'value' => 'active'],
        ['label' => 'Inactive', 'value' => 'inactive'],
    ]);
});

it('stores the id of a pluck(name, id) closure, keeps duplicate names apart and resolves lazily', function () {
    $users = collect([
        ['id' => 7, 'name' => 'Ana'],
        ['id' => 9, 'name' => 'Ana'],
        ['id' => 12, 'name' => 'Rui'],
    ]);
    $calls = 0;
    $field = Select::make('owner_id')->options(function (?Request $request) use ($users, &$calls): array {
        $calls++;

        return $users->pluck('name', 'id')->all();
    });

    expect($calls)->toBe(0);

    $expected = [
        ['label' => 'Ana', 'value' => 7],
        ['label' => 'Ana', 'value' => 9],
        ['label' => 'Rui', 'value' => 12],
    ];

    expect($field->getOptions())->toBe($expected)
        ->and($calls)->toBe(1)
        ->and($field->toArray()['options'])->toBe($expected);
});

it('reads a Collection returned from an options() closure, as Nova does with collect()', function () {
    $field = Select::make('owner_id')->options(fn () => collect([7 => 'Ana', 9 => 'Rui']));

    expect($field->getOptions())->toBe([
        ['label' => 'Ana', 'value' => 7],
        ['label' => 'Rui', 'value' => 9],
    ]);
});

it('normalises a Collection returned from searchOptionsUsing()', function () {
    $field = Select::make('model')->searchOptionsUsing(fn (string $term) => collect(['gpt-4o' => 'GPT-4o']));

    expect($field->searchOptions('gpt'))->toBe([
        ['label' => 'GPT-4o', 'value' => 'gpt-4o'],
    ]);
});

it('rejects a Collection passed straight to options(), and names ->all() as the fix', function () {
    Select::make('status')->options(collect(['a' => 'A']));
})->throws(InvalidArgumentException::class, 'Pass an array: call ->all() on a Collection.');

it('reads a list as values 0, 1, 2 like Nova does', function () {
    $field = Select::make('size')->options(['Small', 'Large']);

    expect($field->getOptions())->toBe([
        ['label' => 'Small', 'value' => 0],
        ['label' => 'Large', 'value' => 1],
    ]);
});

it('keeps non-sequential integer keys as the stored values', function () {
    $field = Select::make('priority')->options([1 => 'Low', 5 => 'High']);

    expect($field->getOptions())->toBe([
        ['label' => 'Low', 'value' => 1],
        ['label' => 'High', 'value' => 5],
    ]);
});

it('reads Nova grouped options and carries the group in the payload', function () {
    $field = Select::make('size')->options([
        'MS' => ['label' => 'Small', 'group' => 'Men Sizes'],
        'WS' => ['label' => 'Small', 'group' => 'Women Sizes'],
        'XL' => ['label' => 'Extra large'],
    ]);

    expect($field->getOptions())->toBe([
        ['label' => 'Small', 'value' => 'MS', 'group' => 'Men Sizes'],
        ['label' => 'Small', 'value' => 'WS', 'group' => 'Women Sizes'],
        ['label' => 'Extra large', 'value' => 'XL'],
    ]);
});

it('rejects the pre-v2 nested group format with a message naming the field and the option', function () {
    Select::make('stack')->options(['Backend' => ['PHP' => 'php']]);
})->throws(InvalidArgumentException::class, 'Select [stack]: the option [Backend] maps to an array that is not a grouped option.');

it('rejects a grouped option with keys other than label and group', function () {
    Select::make('size')->options(['MS' => ['label' => 'Small', 'color' => 'blue']]);
})->throws(InvalidArgumentException::class, 'the option [MS] maps to an array that is not a grouped option');

it('raises the grouped-option error from a closure when the options are read', function () {
    Select::make('stack')->options(fn () => ['Backend' => ['PHP' => 'php']])->getOptions();
})->throws(InvalidArgumentException::class, 'Select [stack]: the option [Backend]');

it('rejects a label that cannot be a string', function () {
    Select::make('status')->options(['draft' => new stdClass]);
})->throws(InvalidArgumentException::class, 'Select [status]: the label of option [draft] must be a string, got stdClass.');

it('rejects a string that is not an enum class', function () {
    Select::make('status')->options('NotAnEnum');
})->throws(InvalidArgumentException::class, 'Select [status]: options() received [NotAnEnum], which is neither an array, a Closure nor an enum class.');

it('fails loudly when optionsFromMap() is called, since options() replaced it', function () {
    Select::make('plan')->optionsFromMap(['free' => 'Free']);
})->throws(Error::class, 'Call to undefined method Martis\Fields\Select::optionsFromMap()');

// ---------------------------------------------------------------------------
// Enum support (v1.1)
// ---------------------------------------------------------------------------

enum SelectFieldStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case ArchivedAt = 'archived';
}

enum SelectFieldPureColor
{
    case Red;
    case Green;
    case BlueLight;
}

it('options() accepts a backed-enum class and derives value + headline label', function () {
    $field = Select::make('status')->options(SelectFieldStatus::class);

    expect($field->getOptions())->toEqual([
        ['label' => 'Draft', 'value' => 'draft'],
        ['label' => 'Published', 'value' => 'published'],
        ['label' => 'Archived At', 'value' => 'archived'],
    ]);
});

it('options() accepts a pure enum class and uses case name as both value and label', function () {
    $field = Select::make('color')->options(SelectFieldPureColor::class);

    expect($field->getOptions())->toEqual([
        ['label' => 'Red', 'value' => 'Red'],
        ['label' => 'Green', 'value' => 'Green'],
        ['label' => 'Blue Light', 'value' => 'BlueLight'],
    ]);
});

it('displayUsingLabels defaults to true so the index/detail cell shows labels', function () {
    $field = Select::make('status');

    expect($field->isDisplayingLabels())->toBeTrue();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra)->toHaveKey('displayLabels', true);
});

it('displayUsingLabels() keeps the flag true and is exposed in the schema payload', function () {
    $field = Select::make('status')->displayUsingLabels();

    expect($field->isDisplayingLabels())->toBeTrue();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra['displayLabels'])->toBeTrue();
});

it('displayUsingValues() flips the flag so the index/detail cell renders raw values', function () {
    $field = Select::make('country_code')->displayUsingValues();

    expect($field->isDisplayingLabels())->toBeFalse();

    $extra = (function () {
        return $this->extraAttributes();
    })->call($field);

    expect($extra['displayLabels'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Option search box + custom values (v1.37.0)
// ---------------------------------------------------------------------------

it('searchableOptions and allowCustomValues default to false and are exposed in the schema payload', function () {
    $field = Select::make('model');

    expect($field->hasSearchableOptions())->toBeFalse()
        ->and($field->allowsCustomValues())->toBeFalse();

    $payload = $field->toArray();

    expect($payload['searchableOptions'])->toBeFalse()
        ->and($payload['allowCustomValues'])->toBeFalse()
        ->and($payload['remoteOptionsSearch'])->toBeFalse();
});

it('searchableOptions() turns the option search box on without touching the column-search flag', function () {
    $field = Select::make('model')->searchableOptions();

    expect($field->hasSearchableOptions())->toBeTrue()
        ->and($field->isSearchable())->toBeFalse();

    $payload = $field->toArray();

    expect($payload['searchableOptions'])->toBeTrue()
        ->and($payload['searchable'])->toBeFalse();
});

it('searchable() keeps its column-search meaning on a Select and never enables the option search box', function () {
    $field = Select::make('status')->searchable();

    expect($field->isSearchable())->toBeTrue()
        ->and($field->hasSearchableOptions())->toBeFalse();
});

it('allowCustomValues() flips the flag and chains with searchableOptions()', function () {
    $field = Select::make('model')->searchableOptions()->allowCustomValues();

    expect($field->allowsCustomValues())->toBeTrue();

    $payload = $field->toArray();

    expect($payload['allowCustomValues'])->toBeTrue()
        ->and($payload['searchableOptions'])->toBeTrue();
});

it('both setters accept an explicit false to switch the behaviour back off', function () {
    $field = Select::make('model')->searchableOptions()->allowCustomValues();

    $field->searchableOptions(false)->allowCustomValues(false);

    expect($field->hasSearchableOptions())->toBeFalse()
        ->and($field->allowsCustomValues())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Server-side option search (v1.37.0)
// ---------------------------------------------------------------------------

it('searchOptionsUsing() registers a resolver, flags the field as remote and turns the search box on', function () {
    $field = Select::make('model')->searchOptionsUsing(fn (string $term) => ['gpt-4o' => 'gpt-4o']);

    expect($field->hasRemoteOptionsSearch())->toBeTrue()
        ->and($field->hasSearchableOptions())->toBeTrue();

    $payload = $field->toArray();

    expect($payload['remoteOptionsSearch'])->toBeTrue()
        ->and($payload['searchableOptions'])->toBeTrue();
});

it('searchOptions() runs the resolver with the term and the request and normalises like options()', function () {
    $seen = [];
    $field = Select::make('model')->searchOptionsUsing(function (string $term, ?Request $request) use (&$seen): array {
        $seen = [$term, $request];

        return ['gpt-4o' => 'GPT-4o', 'claude-opus-5' => 'Claude Opus 5'];
    });
    $request = Request::create('/martis/api/resources/x/fields/model/options', 'GET');

    expect($field->searchOptions('gpt', $request))->toEqual([
        ['label' => 'GPT-4o', 'value' => 'gpt-4o'],
        ['label' => 'Claude Opus 5', 'value' => 'claude-opus-5'],
    ]);
    expect($seen[0])->toBe('gpt')
        ->and($seen[1])->toBe($request);
});

it('searchOptions() reads a list like options(): values 0, 1, 2', function () {
    $field = Select::make('model')->searchOptionsUsing(fn (string $term) => ['gpt-4o', 'gpt-4o-mini']);

    expect($field->searchOptions('gpt'))->toBe([
        ['label' => 'gpt-4o', 'value' => 0],
        ['label' => 'gpt-4o-mini', 'value' => 1],
    ]);
});

it('searchOptions() returns an empty list when the resolver does not return an array', function () {
    $field = Select::make('model')->searchOptionsUsing(fn (string $term) => null);

    expect($field->searchOptions('x'))->toBe([]);
});

it('searchOptions() returns an empty list when no resolver is registered', function () {
    expect(Select::make('model')->searchOptions('x'))->toBe([]);
});

it('searchOptionsUsing() leaves getOptions() (the initial list) untouched', function () {
    $field = Select::make('model')->options(['a' => 'a'])->searchOptionsUsing(fn () => ['b' => 'b']);

    expect($field->getOptions())->toEqual([['label' => 'a', 'value' => 'a']]);
});
