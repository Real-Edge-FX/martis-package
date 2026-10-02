<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\BelongsTo;
use Martis\Fields\Boolean;
use Martis\Fields\BooleanGroup;
use Martis\Fields\Code;
use Martis\Fields\Field;
use Martis\Fields\Gravatar;
use Martis\Fields\Icon;
use Martis\Fields\KeyValue;
use Martis\Fields\MorphTo;
use Martis\Fields\MultiSelect;
use Martis\Fields\Slug;
use Martis\Fields\Sparkline;
use Martis\Fields\Text;
use Martis\Fields\Url;

// ---------------------------------------------------------------------------
// Every field's fill() starts with the seams of Field::fill(): a readonly
// field (a closure resolved per request included) writes nothing, and a
// fillUsing() callback takes over the write. A field that tests the static
// `readonly` flag only, or skips a seam, lets a request write what the form
// showed as locked (F024, F052).
// ---------------------------------------------------------------------------

class FillSeamsModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * The fields whose fill() writes an attribute from a scalar-ish value, with
 * a value each accepts.
 *
 * @return array<string, array{0: Closure(): Field, 1: mixed}>
 */
function fillSeamFields(): array
{
    return [
        'Text' => [fn () => Text::make('attr'), 'value'],
        'Boolean' => [fn () => Boolean::make('attr'), true],
        'Code' => [fn () => Code::make('attr'), 'echo 1;'],
        'Icon' => [fn () => Icon::make('attr')->stored(), 'crown'],
        'Gravatar (url)' => [fn () => Gravatar::make('attr')->fromUrl(), 'https://example.com/a.png'],
        'Sparkline' => [fn () => Sparkline::make('attr'), [1, 2, 3]],
        'Slug' => [fn () => Slug::make('attr'), 'a-slug'],
        'Url' => [fn () => Url::make('attr'), 'https://example.com'],
        'KeyValue' => [fn () => KeyValue::make('attr'), ['a' => '1']],
        'MultiSelect' => [fn () => MultiSelect::make('attr')->options(['a' => 'A']), ['a']],
        'BooleanGroup' => [fn () => BooleanGroup::make('attr')->options(['a' => 'A']), ['a' => true]],
    ];
}

it('does not write a value while a readonly closure holds the field locked', function (Closure $make, mixed $value) {
    $model = new FillSeamsModel;
    $field = $make()->readonly(fn () => true);

    $field->fill($model, $value);

    expect($model->getAttributes())->not->toHaveKey('attr');
})->with(fn () => fillSeamFields());

it('writes the value once a readonly closure unlocks the field', function (Closure $make, mixed $value) {
    $model = new FillSeamsModel;
    $field = $make()->readonly(fn () => false);

    $field->fill($model, $value);

    expect($model->getAttributes())->toHaveKey('attr');
})->with(fn () => fillSeamFields());

it('hands the write to a fillUsing() callback instead of the attribute', function (Closure $make, mixed $value) {
    $model = new FillSeamsModel;
    $seen = [];
    $field = $make()->fillUsing(function (Model $model, mixed $received) use (&$seen): void {
        $seen[] = $received;
        $model->setAttribute('marker', 'called');
    });

    $field->fill($model, $value);

    expect($seen)->toHaveCount(1)
        ->and($model->getAttribute('marker'))->toBe('called')
        ->and($model->getAttributes())->not->toHaveKey('attr');
})->with(fn () => fillSeamFields());

it('does not write a relationship key while a readonly closure holds the field locked', function () {
    $model = new FillSeamsModel;
    BelongsTo::make('team')->readonly(fn () => true)->fill($model, 5);

    expect($model->getAttributes())->not->toHaveKey('team_id');
});

it('lets a nullable() closure decide whether MorphTo clears its columns', function () {
    $model = new FillSeamsModel(['commentable_type' => 'App\\Post', 'commentable_id' => 4]);

    MorphTo::make('commentable')->nullable(fn () => false)->fill($model, null);
    expect($model->getAttribute('commentable_id'))->toBe(4);

    MorphTo::make('commentable')->nullable(fn () => true)->fill($model, null);
    expect($model->getAttribute('commentable_id'))->toBeNull()
        ->and($model->getAttribute('commentable_type'))->toBeNull();
});
