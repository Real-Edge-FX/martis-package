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
