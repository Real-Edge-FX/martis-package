<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Hidden;

class HiddenTestModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// A hidden input is not a trust boundary: whatever the client posts for the
// attribute is written to the model. These tests pin that behaviour (the
// reason the docblock and the docs tell developers never to carry a tenant or
// owner column through a Hidden field) and the three server-side alternatives
// the docs recommend.

it('writes whatever the client posts for the attribute', function () {
    $model = new HiddenTestModel(['tenant_id' => 1]);

    Hidden::make('tenant_id')->fill($model, 99);

    expect($model->tenant_id)->toBe(99);
});

it('never reads the posted value of a readonly() Hidden field', function () {
    $model = new HiddenTestModel(['tenant_id' => 1]);

    Hidden::make('tenant_id')->readonly()->fill($model, 99);

    expect($model->tenant_id)->toBe(1);
});

it('lets a fillUsing() callback ignore the posted value and write the server-side one', function () {
    $model = new HiddenTestModel;

    Hidden::make('tenant_id')
        ->fillUsing(function (Model $model): void {
            $model->setAttribute('tenant_id', 7);
        })
        ->fill($model, 99);

    expect($model->tenant_id)->toBe(7);
});

it('says in its docblock that a Hidden value is client-controlled and where to set tenant and owner columns', function () {
    // The docblock wraps its lines: compare the words, not the line breaks.
    $docblock = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', (string) (new ReflectionClass(Hidden::class))->getDocComment());

    expect($docblock)
        ->toContain('client-controlled')
        ->toContain('not a trust boundary')
        ->toContain('fillUsing()')
        ->toContain('readonly()')
        ->toContain('beforeSave()')
        // The old wording recommended the field for exactly the values it must not carry.
        ->not->toContain('passing internal')
        ->not->toContain('(tenant IDs, default statuses');
});

it('documents the same warning on the Hidden field docs page', function () {
    $docs = (string) file_get_contents(dirname(__DIR__, 3).'/docs/fields.md');
    $start = strpos($docs, "### Hidden\n");
    $end = strpos($docs, "\n### Badge\n", (int) $start);

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    $section = substr($docs, (int) $start, (int) $end - (int) $start);

    expect($section)
        ->toContain('client-controlled')
        ->toContain('fillUsing()')
        ->toContain('readonly()')
        ->toContain('beforeSave()');
});
