<?php

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Martis\Fields\File;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\MultiSelect;

// ===========================================================================
// Structured writes must not double-encode a cast attribute.
//
// MultiSelect, KeyValue and the multiple-file modes of File / Image persist a
// list or a map. They used to json_encode() it themselves before
// setAttribute(); on an attribute with an `array` / `json` / class cast the
// cast's set() step encoded that string again, so the column held a JSON
// string *of* a JSON string and every later read through the cast returned a
// string instead of an array. The cast now receives the PHP array and
// serialises it once; an uncast column still gets the JSON string.
// ===========================================================================

class StructuredCastArrayModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'labels' => 'array',
        'meta' => 'json',
        'documents' => 'array',
        'photos' => 'array',
    ];
}

class StructuredCastClassModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'labels' => AsCollection::class,
            'meta' => AsArrayObject::class,
            'documents' => AsCollection::using(Collection::class),
        ];
    }
}

class StructuredCastScalarModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'labels' => 'string',
    ];
}

class StructuredUncastModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// MultiSelect
// ---------------------------------------------------------------------------

it('MultiSelect fill() hands an array cast the PHP array so it is encoded once', function () {
    $model = new StructuredCastArrayModel;

    MultiSelect::make('labels')->fill($model, ['available', 'reserved']);

    expect($model->labels)->toBe(['available', 'reserved'])
        ->and($model->getAttributes()['labels'])->toBe('["available","reserved"]');
});

it('MultiSelect fill() hands a class cast the PHP array', function () {
    $model = new StructuredCastClassModel;

    MultiSelect::make('labels')->fill($model, ['a', 'b']);

    expect($model->labels)->toBeInstanceOf(Collection::class)
        ->and($model->labels->all())->toBe(['a', 'b'])
        ->and($model->getAttributes()['labels'])->toBe('["a","b"]');
});

it('MultiSelect fill() hands a class cast with arguments the PHP array', function () {
    $model = new StructuredCastClassModel;

    MultiSelect::make('documents')->fill($model, ['x']);

    expect($model->documents->all())->toBe(['x'])
        ->and($model->getAttributes()['documents'])->toBe('["x"]');
});

it('MultiSelect fill() still writes a JSON string to an uncast column', function () {
    $model = new StructuredUncastModel;

    MultiSelect::make('labels')->fill($model, ['php', 'laravel']);

    expect($model->getAttribute('labels'))->toBe('["php","laravel"]');
});

it('MultiSelect fill() still writes a JSON string when the cast is a scalar type', function () {
    $model = new StructuredCastScalarModel;

    MultiSelect::make('labels')->fill($model, ['php']);

    expect($model->getAttributes()['labels'])->toBe('["php"]');
});

it('MultiSelect fill() stores null through an array cast for an empty selection', function () {
    $model = new StructuredCastArrayModel;

    MultiSelect::make('labels')->fill($model, []);

    expect($model->labels)->toBeNull();
});

it('MultiSelect resolve() reads back the array the cast produced', function () {
    $model = new StructuredCastArrayModel;
    $field = MultiSelect::make('labels');

    $field->fill($model, ['available', 'reserved']);

    expect($field->resolve($model))->toBe(['available', 'reserved']);
});

// ---------------------------------------------------------------------------
// KeyValue
// ---------------------------------------------------------------------------

it('KeyValue fill() hands a json cast the associative map so it is encoded once', function () {
    $model = new StructuredCastArrayModel;

    KeyValue::make('meta')->fill($model, [['key' => 'color', 'value' => 'blue']]);

    expect($model->meta)->toBe(['color' => 'blue'])
        ->and($model->getAttributes()['meta'])->toBe('{"color":"blue"}');
});

it('KeyValue fill() hands an AsArrayObject cast the associative map', function () {
    $model = new StructuredCastClassModel;

    KeyValue::make('meta')->fill($model, ['env' => 'prod']);

    expect($model->meta->toArray())->toBe(['env' => 'prod'])
        ->and($model->getAttributes()['meta'])->toBe('{"env":"prod"}');
});

it('KeyValue fill() still writes a JSON string to an uncast column', function () {
    $model = new StructuredUncastModel;

    KeyValue::make('meta')->fill($model, ['env' => 'prod']);

    expect($model->getAttribute('meta'))->toBe('{"env":"prod"}');
});

it('KeyValue encodeToJson() keeps returning the JSON string', function () {
    expect(KeyValue::make('meta')->encodeToJson([['key' => 'a', 'value' => '1']]))->toBe('{"a":"1"}')
        ->and(KeyValue::make('meta')->encodeToJson(null))->toBeNull();
});

// ---------------------------------------------------------------------------
// File / Image (multiple mode)
// ---------------------------------------------------------------------------

it('File multiple fill() hands an array cast the path list so it is encoded once', function () {
    $model = new StructuredCastArrayModel;
    $model->setRawAttributes(['documents' => '["docs\/a.pdf","docs\/b.pdf"]']);

    File::make('documents')->multiple()->fill($model, [
        'files' => [],
        'existing' => ['docs/a.pdf', 'docs/b.pdf'],
    ]);

    expect($model->documents)->toBe(['docs/a.pdf', 'docs/b.pdf'])
        ->and($model->getAttributes()['documents'])->toBe('["docs\/a.pdf","docs\/b.pdf"]');
});

it('File multiple fill() hands an array cast an empty list when everything was removed', function () {
    $model = new StructuredCastArrayModel;

    File::make('documents')->multiple()->fill($model, []);

    expect($model->documents)->toBe([])
        ->and($model->getAttributes()['documents'])->toBe('[]');
});

it('File multiple fill() still writes a JSON string to an uncast column', function () {
    $model = new StructuredUncastModel;
    $model->setRawAttributes(['documents' => '["docs\/a.pdf"]']);

    File::make('documents')->multiple()->fill($model, [
        'files' => [],
        'existing' => ['docs/a.pdf'],
    ]);

    expect($model->getAttribute('documents'))->toBe('["docs\/a.pdf"]');
});

it('Image multiple fill() hands an array cast the path list so it is encoded once', function () {
    $model = new StructuredCastArrayModel;

    Image::make('photos')->multiple()->fill($model, [
        'files' => [],
        'existing' => ['img/a.jpg'],
    ]);

    expect($model->photos)->toBe(['img/a.jpg'])
        ->and($model->getAttributes()['photos'])->toBe('["img\/a.jpg"]');
});

it('Image multiple fill() still writes a JSON string to an uncast column', function () {
    $model = new StructuredUncastModel;

    Image::make('photos')->multiple()->fill($model, []);

    expect($model->getAttribute('photos'))->toBe('[]');
});
