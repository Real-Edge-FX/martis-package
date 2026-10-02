<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Martis\Fields\Password;

// Password::fill() hashes what it writes, but it starts with the seams of
// Field::fill() like every other field: a readonly closure locks it, and a
// fillUsing() callback takes the write over (it used to be ignored).

class PasswordFillSeamsModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

it('hashes the value it writes', function () {
    $model = new PasswordFillSeamsModel;

    Password::make('password')->fill($model, 'secret-pass');

    expect(Hash::check('secret-pass', (string) $model->getAttribute('password')))->toBeTrue();
});

it('does not write while a readonly closure holds the field locked', function () {
    $model = new PasswordFillSeamsModel;

    Password::make('password')->readonly(fn () => true)->fill($model, 'secret-pass');

    expect($model->getAttributes())->not->toHaveKey('password');
});

it('hands the write to a fillUsing() callback instead of hashing into the attribute', function () {
    $model = new PasswordFillSeamsModel;
    $received = null;

    Password::make('password')
        ->fillUsing(function (Model $model, mixed $value) use (&$received): void {
            $received = $value;
            $model->setAttribute('marker', 'called');
        })
        ->fill($model, 'secret-pass');

    expect($received)->toBe('secret-pass')
        ->and($model->getAttribute('marker'))->toBe('called')
        ->and($model->getAttributes())->not->toHaveKey('password');
});

it('does not call a fillUsing() callback for an empty value, which never overwrites the password', function () {
    $model = new PasswordFillSeamsModel;
    $calls = 0;

    $field = Password::make('password')->fillUsing(function () use (&$calls): void {
        $calls++;
    });
    $field->fill($model, null);
    $field->fill($model, '');

    expect($calls)->toBe(0);
});
