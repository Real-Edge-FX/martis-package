<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Slug;

class SlugTestModel extends Model
{
    protected $table = 'posts';

    protected $fillable = ['slug', 'title', 'published_at'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Slug::make creates a slug field', function () {
    $field = Slug::make('slug');

    expect($field->attribute())->toBe('slug')
        ->and($field->label())->toBe('Slug')
        ->and($field->type())->toBe('slug');
});

// ---------------------------------------------------------------------------
// Core API — from() + separator()
// ---------------------------------------------------------------------------

it('Slug::from() stores the source attribute', function () {
    $field = Slug::make('slug')->from('title');

    expect($field->getSourceAttribute())->toBe('title');
});

it('Slug::separator() configures the token separator (default "-")', function () {
    $field = Slug::make('slug');
    expect($field->getSeparator())->toBe('-');

    $field->separator('_');
    expect($field->getSeparator())->toBe('_');
});

// ---------------------------------------------------------------------------
// generate() — unicode-safe slugify
// ---------------------------------------------------------------------------

it('Slug::generate handles unicode (São Paulo → sao-paulo)', function () {
    $field = Slug::make('slug');

    expect($field->generate('São Paulo'))->toBe('sao-paulo')
        ->and($field->generate('Ação Judicial'))->toBe('acao-judicial')
        ->and($field->generate('  Hello  World  '))->toBe('hello-world');
});

it('Slug::generate respects a custom separator', function () {
    $field = Slug::make('slug')->separator('_');

    expect($field->generate('Hello World'))->toBe('hello_world');
});

// ---------------------------------------------------------------------------
// fill() — normalises and persists
// ---------------------------------------------------------------------------

it('Slug::fill() normalises the incoming value', function () {
    $model = new SlugTestModel;
    $field = Slug::make('slug');

    $field->fill($model, 'My First Post!');

    expect($model->getAttribute('slug'))->toBe('my-first-post');
});

it('Slug::fill() preserves an already-valid slug', function () {
    $model = new SlugTestModel;
    $field = Slug::make('slug');

    $field->fill($model, 'already-a-slug');

    expect($model->getAttribute('slug'))->toBe('already-a-slug');
});

it('Slug::fill() stores null on empty string', function () {
    $model = new SlugTestModel;
    $field = Slug::make('slug')->nullable();

    $field->fill($model, '');

    expect($model->getAttribute('slug'))->toBeNull();
});

// ---------------------------------------------------------------------------
// ⭐ Differential — reserved words
// ---------------------------------------------------------------------------

it('Slug::reserved() stores the reserved list', function () {
    $field = Slug::make('slug')->reserved(['admin', 'api', 'login']);

    expect($field->getReserved())->toBe(['admin', 'api', 'login']);
});

it('Slug::reserved() normalises mixed-case entries to lowercase', function () {
    $field = Slug::make('slug')->reserved(['Admin', 'LOGIN', 'Api']);

    expect($field->getReserved())->toBe(['admin', 'login', 'api']);
});

it('Slug validation rejects a mixed-case reserved word passed to reserved()', function () {
    $field = Slug::make('slug')->reserved(['Admin']);
    $rules = $field->buildRules();
    $closure = $rules[count($rules) - 1];

    $failed = null;
    $closure('slug', 'admin', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    expect($failed)->toBeString()->and($failed)->toContain('admin');
});

it('Slug validation rule rejects a reserved slug', function () {
    $field = Slug::make('slug')->reserved(['admin']);
    $rules = $field->buildRules();

    // Last closure is our slug-specific rule.
    $closure = $rules[count($rules) - 1];
    $failed = null;
    $closure('slug', 'admin', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    expect($failed)->toBeString()->and($failed)->toContain('admin');
});

it('Slug validation rule is tolerant of uppercase and spaces (fill() canonicalises)', function () {
    $field = Slug::make('slug');
    $rules = $field->buildRules();
    $closure = $rules[count($rules) - 1];

    $failed = null;
    $closure('slug', 'Not A Slug', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    // Accepted — the closure knows `fill()` will canonicalise to "not-a-slug".
    expect($failed)->toBeNull();
});

it('Slug validation rule rejects input that normalises to the empty string', function () {
    $field = Slug::make('slug');
    $rules = $field->buildRules();
    $closure = $rules[count($rules) - 1];

    $failed = null;
    $closure('slug', '!!!', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    expect($failed)->toBeString();
});

it('Slug validation rule rejects reserved values after normalisation (e.g. " Admin ")', function () {
    $field = Slug::make('slug')->reserved(['admin']);
    $rules = $field->buildRules();
    $closure = $rules[count($rules) - 1];

    $failed = null;
    $closure('slug', ' Admin ', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    expect($failed)->toBeString()->and($failed)->toContain('admin');
});

it('Slug validation rule passes when the value is already a valid slug', function () {
    $field = Slug::make('slug')->reserved(['admin']);
    $rules = $field->buildRules();
    $closure = $rules[count($rules) - 1];

    $failed = null;
    $closure('slug', 'hello-world', function ($msg) use (&$failed) {
        $failed = $msg;
    });

    expect($failed)->toBeNull();
});

// ---------------------------------------------------------------------------
// ⭐ Differential — lockAfter
// ---------------------------------------------------------------------------

it('Slug::lockAfter() locks an existing record when the condition returns true', function () {
    $model = new SlugTestModel(['slug' => 'original-slug', 'published_at' => '2024-01-01']);
    $model->exists = true;

    $field = Slug::make('slug')->lockAfter(fn ($m) => $m->getAttribute('published_at') !== null);

    expect($field->isLockedFor($model))->toBeTrue();
});

it('Slug::lockAfter() does not lock when the condition is false', function () {
    $model = new SlugTestModel(['slug' => 'draft', 'published_at' => null]);
    $model->exists = true;

    $field = Slug::make('slug')->lockAfter(fn ($m) => $m->getAttribute('published_at') !== null);

    expect($field->isLockedFor($model))->toBeFalse();
});

it('Slug::fill() silently ignores writes on a locked record', function () {
    $model = new SlugTestModel(['slug' => 'original-slug', 'published_at' => '2024-01-01']);
    $model->exists = true;

    $field = Slug::make('slug')->lockAfter(fn ($m) => $m->getAttribute('published_at') !== null);
    $field->fill($model, 'hijacked-slug');

    expect($model->getAttribute('slug'))->toBe('original-slug');
});

it('Slug::fill() still writes on a new (non-persisted) record even with lockAfter configured', function () {
    $model = new SlugTestModel;
    // exists=false by default

    $field = Slug::make('slug')->lockAfter(fn ($m) => true);
    $field->fill($model, 'new-post');

    expect($model->getAttribute('slug'))->toBe('new-post');
});

// ---------------------------------------------------------------------------
// toArray — metadata emitted to the React frontend
// ---------------------------------------------------------------------------

it('Slug::toArray emits sourceAttribute, separator and reserved when configured', function () {
    $field = Slug::make('slug')
        ->from('title')
        ->separator('_')
        ->reserved(['admin', 'api']);

    $arr = $field->toArray();

    expect($arr)
        ->toHaveKey('sourceAttribute', 'title')
        ->toHaveKey('separator', '_')
        ->toHaveKey('reserved', ['admin', 'api']);
});

it('Slug::toArray emits hasLock when lockAfter is configured', function () {
    $field = Slug::make('slug')->lockAfter(fn () => false);

    $arr = $field->toArray();

    expect($arr)->toHaveKey('hasLock', true);
});

it('Slug::toArray omits optional keys when not configured', function () {
    $field = Slug::make('slug');

    $arr = $field->toArray();

    expect($arr)
        ->not->toHaveKey('sourceAttribute')
        ->not->toHaveKey('reserved')
        ->not->toHaveKey('hasLock');
});
