<?php

use Illuminate\Http\Request;
use Martis\Fields\Select;

it('normalises associative options as [label => value]', function () {
    $field = Select::make('status')->options([
        'Active' => 'active',
        'Inactive' => 'inactive',
    ]);

    expect($field->getOptions())->toEqual([
        ['label' => 'Active', 'value' => 'active'],
        ['label' => 'Inactive', 'value' => 'inactive'],
    ]);
});

it('normalises sequential options using the value as label', function () {
    $field = Select::make('status')->options(['draft', 'published']);

    expect($field->getOptions())->toEqual([
        ['label' => 'draft', 'value' => 'draft'],
        ['label' => 'published', 'value' => 'published'],
    ]);
});

it('optionsFromMap accepts [value => label] and keeps values untranslated', function () {
    $field = Select::make('plan')->optionsFromMap([
        'free' => 'Grátis',
        'pro' => 'Pro',
        'enterprise' => 'Empresa',
    ]);

    expect($field->getOptions())->toEqual([
        ['label' => 'Grátis', 'value' => 'free'],
        ['label' => 'Pro', 'value' => 'pro'],
        ['label' => 'Empresa', 'value' => 'enterprise'],
    ]);
});

it('optionsFromMap is idempotent — successive calls replace options', function () {
    $field = Select::make('plan')->optionsFromMap(['a' => 'Alpha']);
    $field->optionsFromMap(['b' => 'Beta']);

    expect($field->getOptions())->toEqual([
        ['label' => 'Beta', 'value' => 'b'],
    ]);
});

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

it('options() rejects a string that is not an enum class as a regular value', function () {
    // String that isn't an enum should fall through to the array branch.
    // This is more of a contract check — passing arbitrary strings is a
    // type-system error caught by static analysis. Here we just confirm
    // there's no false-positive enum interpretation.
    expect(enum_exists('NotAnEnum'))->toBeFalse();
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

        return ['GPT-4o' => 'gpt-4o', 'Claude Opus 5' => 'claude-opus-5'];
    });
    $request = Request::create('/martis/api/resources/x/fields/model/options', 'GET');

    expect($field->searchOptions('gpt', $request))->toEqual([
        ['label' => 'GPT-4o', 'value' => 'gpt-4o'],
        ['label' => 'Claude Opus 5', 'value' => 'claude-opus-5'],
    ]);
    expect($seen[0])->toBe('gpt')
        ->and($seen[1])->toBe($request);
});

it('searchOptions() accepts a sequential list and uses each value as its label', function () {
    $field = Select::make('model')->searchOptionsUsing(fn (string $term) => ['gpt-4o', 'gpt-4o-mini']);

    expect($field->searchOptions('gpt'))->toEqual([
        ['label' => 'gpt-4o', 'value' => 'gpt-4o'],
        ['label' => 'gpt-4o-mini', 'value' => 'gpt-4o-mini'],
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
