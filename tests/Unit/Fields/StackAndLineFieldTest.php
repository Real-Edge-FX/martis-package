<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\LineVariant;
use Martis\FieldContext;
use Martis\Fields\Line;
use Martis\Fields\Stack;

// ---------------------------------------------------------------------------
// Test model fixture
// ---------------------------------------------------------------------------

class StackTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'company', 'slug', 'country'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Line — construction + variants
// ---------------------------------------------------------------------------

it('Line::make creates a line field hidden from forms', function () {
    $line = Line::make('name');

    expect($line->type())->toBe('line')
        ->and($line->attribute())->toBe('name')
        ->and($line->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($line->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($line->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($line->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

it('Line default variant is base', function () {
    expect(Line::make('name')->getVariant())->toBe(LineVariant::Base);
});

it('Line variant setters are mutually exclusive', function () {
    expect(Line::make('name')->asHeading()->getVariant())->toBe(LineVariant::Heading)
        ->and(Line::make('name')->asSmall()->getVariant())->toBe(LineVariant::Small)
        ->and(Line::make('name')->asMuted()->getVariant())->toBe(LineVariant::Muted)
        ->and(Line::make('name')->asCode()->getVariant())->toBe(LineVariant::Code)
        ->and(Line::make('name')->asHeading()->asBase()->getVariant())->toBe(LineVariant::Base);
});

it('Line serialises its variant in extraAttributes', function () {
    $schema = Line::make('name')->asHeading()->toArray();

    expect($schema['variant'])->toBe('heading');
});

// ---------------------------------------------------------------------------
// Line — subtitleFrom (⭐ Martis differential)
// ---------------------------------------------------------------------------

it('Line subtitleFrom(attribute) resolves the subtitle from the model', function () {
    $model = new StackTestModel(['name' => 'João', 'company' => 'Acme']);
    $line = Line::make('name')->subtitleFrom('company');

    expect($line->resolveSubtitle($model))->toBe('Acme');
});

it('Line subtitleFrom(Closure) receives the model and returns a string', function () {
    $model = new StackTestModel(['name' => 'Jane', 'email' => 'jane@x.co']);
    $line = Line::make('name')->subtitleFrom(fn ($m) => strtoupper($m->email));

    expect($line->resolveSubtitle($model))->toBe('JANE@X.CO');
});

it('Line subtitleFrom returns null when the attribute is null', function () {
    $model = new StackTestModel(['name' => 'Bob']);
    $line = Line::make('name')->subtitleFrom('missing');

    expect($line->resolveSubtitle($model))->toBeNull();
});

it('Line without subtitleFrom returns null', function () {
    $model = new StackTestModel(['name' => 'Alice']);

    expect(Line::make('name')->resolveSubtitle($model))->toBeNull();
});

it('Line subtitleFrom emits the attribute in the schema when it is a string', function () {
    $schema = Line::make('name')->subtitleFrom('company')->toArray();

    expect($schema['subtitleAttribute'])->toBe('company');
});

it('Line subtitleFrom with Closure emits the has-callback flag instead of an attribute', function () {
    $schema = Line::make('name')->subtitleFrom(fn ($m) => 'x')->toArray();

    expect($schema)->not->toHaveKey('subtitleAttribute')
        ->and($schema['hasSubtitleCallback'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Stack — construction and composition
// ---------------------------------------------------------------------------

it('Stack::make accepts an array of Lines and exposes them', function () {
    $stack = Stack::make('identity', [
        Line::make('name')->asHeading(),
        Line::make('email')->asMuted(),
    ]);

    $lines = $stack->getLines();

    expect($stack->type())->toBe('stack')
        ->and($lines)->toHaveCount(2)
        ->and($lines[0]->getVariant())->toBe(LineVariant::Heading)
        ->and($lines[1]->getVariant())->toBe(LineVariant::Muted);
});

it('Stack::make supports the 3-arg (attribute, label, lines) signature', function () {
    $stack = Stack::make('identity', 'Identity', [Line::make('name')]);

    expect($stack->label())->toBe('Identity')
        ->and($stack->getLines())->toHaveCount(1);
});

it('Stack::make ignores non-Line entries so TypeErrors never reach runtime', function () {
    $stack = Stack::make('identity', [Line::make('name'), null, 'foo']);

    expect($stack->getLines())->toHaveCount(1);
});

it('Stack is hidden from forms by default', function () {
    $stack = Stack::make('identity', [Line::make('name')]);

    expect($stack->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($stack->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($stack->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Stack — divider (⭐ Martis differential)
// ---------------------------------------------------------------------------

it('Stack divider is off by default', function () {
    $stack = Stack::make('identity', [Line::make('name')]);

    expect($stack->hasDivider())->toBeFalse();
});

it('Stack divider() toggles the flag and serialises it', function () {
    $stack = Stack::make('identity', [Line::make('name')])->divider();

    expect($stack->hasDivider())->toBeTrue()
        ->and($stack->toArray()['divider'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Stack — per-row resolution (⭐ differential: works on index)
// ---------------------------------------------------------------------------

it('Stack resolveForDisplay wraps every line entry into the __martisStack payload', function () {
    $model = new StackTestModel([
        'name' => 'João',
        'email' => 'joao@acme.pt',
        'company' => 'Acme Lda',
    ]);

    $stack = Stack::make('identity', [
        Line::make('name')->asHeading()->subtitleFrom('company'),
        Line::make('email')->asMuted(),
    ])->divider();

    $payload = $stack->resolveForDisplay($model);

    expect($payload)->toBeArray()
        ->and($payload['__martisStack'])->toBeTrue()
        ->and($payload['divider'])->toBeTrue()
        ->and($payload['entries'])->toHaveCount(2)
        ->and($payload['entries'][0])->toMatchArray([
            'text' => 'João',
            'variant' => 'heading',
            'subtitle' => 'Acme Lda',
        ])
        ->and($payload['entries'][1])->toMatchArray([
            'text' => 'joao@acme.pt',
            'variant' => 'muted',
            'subtitle' => null,
        ]);
});

it('Stack resolveForDisplay tolerates null attribute values', function () {
    $model = new StackTestModel(['name' => 'Solo']);
    $stack = Stack::make('identity', [
        Line::make('name'),
        Line::make('missing'),
    ]);

    $payload = $stack->resolveForDisplay($model);

    expect($payload['entries'][0]['text'])->toBe('Solo')
        ->and($payload['entries'][1]['text'])->toBeNull();
});

it('Stack toArray includes child line schemas for frontend awareness', function () {
    $schema = Stack::make('identity', [
        Line::make('name')->asHeading(),
        Line::make('email')->asSmall(),
    ])->toArray();

    expect($schema['lines'])->toHaveCount(2)
        ->and($schema['lines'][0]['type'])->toBe('line')
        ->and($schema['lines'][0]['variant'])->toBe('heading')
        ->and($schema['lines'][1]['variant'])->toBe('small');
});

it('Stack renders on the INDEX context by default — this is the Martis differential', function () {
    $stack = Stack::make('identity', [Line::make('name')]);

    expect($stack->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
});
