<?php

use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Text;

enum ComputedFieldTestMode: string
{
    case Push = 'push';
    case Feed = 'feed';
}

/**
 * Mirrors the reporting consumer's shape: `mode()` is a plain method that
 * returns a non-relation value, so `getAttribute('mode')` makes Eloquent
 * treat it as a relationship lookup and throw.
 */
class ComputedFieldTestModel extends Model
{
    protected $table = 'channels';

    protected $fillable = ['name', 'kind'];

    public $timestamps = false;

    public function mode(): ComputedFieldTestMode
    {
        return $this->getAttribute('kind') === 'rss' ? ComputedFieldTestMode::Feed : ComputedFieldTestMode::Push;
    }
}

// ---------------------------------------------------------------------------
// The trap computed() exists for
// ---------------------------------------------------------------------------

it('reproduces the Eloquent trap: a field named after a model method throws without computed()', function () {
    $model = new ComputedFieldTestModel(['name' => 'Blog', 'kind' => 'rss']);
    $field = Text::make('mode')->resolveUsing(fn ($value, Model $m) => $m->mode()->value);

    expect(fn () => $field->resolve($model))
        ->toThrow(LogicException::class, 'must return a relationship instance');
});

// ---------------------------------------------------------------------------
// computed(callback)
// ---------------------------------------------------------------------------

it('computed(callback) is the value source and receives (model, attribute, request)', function () {
    $model = new ComputedFieldTestModel(['name' => 'Blog', 'kind' => 'rss']);
    $captured = null;
    $field = Text::make('mode')->computed(function (Model $m, string $attribute, $request) use (&$captured): string {
        $captured = [$m, $attribute, $request];

        return $m->mode()->value;
    });

    expect($field->resolve($model))->toBe('feed');
    expect($captured[0])->toBe($model);
    expect($captured[1])->toBe('mode');
    // No HTTP context in a bare unit test: the request must be null, not a TypeError.
    expect($captured[2])->toBeNull();
});

it('computed(callback) accepts a single-parameter closure', function () {
    $model = new ComputedFieldTestModel(['kind' => 'push']);
    $field = Text::make('mode')->computed(fn (ComputedFieldTestModel $m) => $m->mode()->value);

    expect($field->resolve($model))->toBe('push');
});

it('computed(callback) honours the explicit attribute argument of resolve()', function () {
    $model = new ComputedFieldTestModel(['kind' => 'push']);
    $field = Text::make('mode')->computed(fn (Model $m, string $attribute) => "computed:{$attribute}");

    expect($field->resolve($model, 'other'))->toBe('computed:other');
});

// ---------------------------------------------------------------------------
// computed() without a callback (flag only)
// ---------------------------------------------------------------------------

it('computed() with no callback hands null to resolveUsing() instead of reading the attribute', function () {
    $model = new ComputedFieldTestModel(['kind' => 'rss']);
    $captured = 'sentinel';
    $field = Text::make('mode')
        ->computed()
        ->resolveUsing(function ($value, Model $m, string $attribute) use (&$captured) {
            $captured = [$value, $attribute];

            return $m->mode()->value;
        });

    expect($field->resolve($model))->toBe('feed');
    expect($captured)->toBe([null, 'mode']);
});

it('computed() with no callback and no resolveUsing() resolves to null', function () {
    $model = new ComputedFieldTestModel(['kind' => 'rss']);

    expect(Text::make('mode')->computed()->resolve($model))->toBeNull();
});

it('a later computed() call replaces the callback', function () {
    $model = new ComputedFieldTestModel(['kind' => 'rss']);
    $field = Text::make('mode')->computed(fn () => 'first')->computed(fn () => 'second');

    expect($field->resolve($model))->toBe('second');
    expect(Text::make('mode')->computed(fn () => 'first')->computed()->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Pipeline and strict mode
// ---------------------------------------------------------------------------

it('feeds the computed value through resolveUsing() and displayUsing() in order', function () {
    $model = new ComputedFieldTestModel(['kind' => 'rss']);
    $field = Text::make('mode')
        ->computed(fn (Model $m) => $m->mode()->value)
        ->resolveUsing(fn ($value) => strtoupper((string) $value))
        ->displayUsing([
            fn ($value) => "[{$value}]",
            fn ($value) => "{$value}!",
        ]);

    expect($field->resolve($model))->toBe('FEED');
    expect($field->resolveForDisplay($model))->toBe('[FEED]!');
});

it('resolves a computed field with no backing column under strict missing-attribute mode', function () {
    Model::preventAccessingMissingAttributes(true);

    try {
        $model = new ComputedFieldTestModel(['kind' => 'push']);
        $model->exists = true; // strict mode only guards persisted models

        // Control: the stored path throws under strict mode, proving the guard is active.
        expect(fn () => Text::make('transport_mode')->resolve($model))
            ->toThrow(MissingAttributeException::class);

        $field = Text::make('transport_mode')->computed(fn (Model $m) => $m->mode()->value);

        expect($field->resolve($model))->toBe('push');
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});

// ---------------------------------------------------------------------------
// Regression: stored fields are untouched
// ---------------------------------------------------------------------------

it('keeps reading the model attribute for non-computed fields', function () {
    $model = new ComputedFieldTestModel(['name' => 'Blog']);
    $captured = null;

    expect(Text::make('name')->resolve($model))->toBe('Blog');

    $field = Text::make('name')->resolveUsing(function ($value) use (&$captured) {
        $captured = $value;

        return $value;
    });
    $field->resolve($model);

    expect($captured)->toBe('Blog');
});

it('reports isComputed()', function () {
    expect(Text::make('mode')->isComputed())->toBeFalse();
    expect(Text::make('mode')->computed()->isComputed())->toBeTrue();
    expect(Text::make('mode')->computed(fn () => 'x')->isComputed())->toBeTrue();
});
