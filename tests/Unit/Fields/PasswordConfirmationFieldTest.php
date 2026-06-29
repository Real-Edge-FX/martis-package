<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Martis\Fields\Password;
use Martis\Fields\PasswordConfirmation;
use Martis\Tests\TestCase;

// The Password hashing assertions need the Laravel container (`Hash::check`
// is a facade). Boot the Testbench test case for this file so the container
// is available.
uses(TestCase::class);

class PasswordTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['password'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Password — construction & visibility defaults
// ---------------------------------------------------------------------------

it('Password::make is hidden from index and detail by default', function () {
    $field = Password::make('password');

    $arr = $field->toArray();
    expect($arr['showOnIndex'])->toBeFalse()
        ->and($arr['showOnDetail'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Password — hashing on fill
// ---------------------------------------------------------------------------

it('Password::fill() hashes the value before persisting', function () {
    $model = new PasswordTestModel;
    $field = Password::make('password');

    $field->fill($model, 'secret123');

    $stored = $model->getAttribute('password');
    expect($stored)->toBeString()
        ->and($stored)->not->toBe('secret123')
        ->and(Hash::check('secret123', $stored))->toBeTrue();
});

it('Password::fill() respects a Closure-based readonly guard', function () {
    $model = new PasswordTestModel(['password' => 'original-hash']);

    // Closure that always returns true — field must be treated as readonly.
    $field = Password::make('password')->readonly(fn () => true);
    $field->fill($model, 'should-not-overwrite');

    // The original value must be preserved.
    expect($model->getAttribute('password'))->toBe('original-hash');
});

it('Password::fill() writes when the Closure-based readonly guard returns false', function () {
    $model = new PasswordTestModel;

    $field = Password::make('password')->readonly(fn () => false);
    $field->fill($model, 'new-password');

    $stored = $model->getAttribute('password');
    expect($stored)->toBeString()
        ->and(Hash::check('new-password', $stored))->toBeTrue();
});

it('Password::fill() skips hashing when the value is null or empty', function () {
    $model = new PasswordTestModel(['password' => 'keep-me']);
    $field = Password::make('password');

    $field->fill($model, null);
    expect($model->getAttribute('password'))->toBe('keep-me');

    $field->fill($model, '');
    expect($model->getAttribute('password'))->toBe('keep-me');
});

it('Password::resolve() always returns null — never exposes the hash', function () {
    $model = new PasswordTestModel(['password' => 'already-hashed']);
    $field = Password::make('password');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// ⭐ Differential — withStrengthMeter()
// ---------------------------------------------------------------------------

it('Password::withStrengthMeter() toggles the meter flag', function () {
    $field = Password::make('password');
    expect($field->hasStrengthMeter())->toBeFalse();

    $field->withStrengthMeter();
    expect($field->hasStrengthMeter())->toBeTrue();

    $field->withStrengthMeter(false);
    expect($field->hasStrengthMeter())->toBeFalse();
});

it('Password::toArray emits strengthMeter only when enabled', function () {
    $plain = Password::make('password')->toArray();
    expect($plain)->not->toHaveKey('strengthMeter');

    $withMeter = Password::make('password')->withStrengthMeter()->toArray();
    expect($withMeter)->toHaveKey('strengthMeter', true);
});

// ---------------------------------------------------------------------------
// ⭐ Differential — declarative complexity requirements
// ---------------------------------------------------------------------------

it('Password requirements add matching Laravel rules to buildRules()', function () {
    $field = Password::make('password')
        ->minLength(10)
        ->requireUppercase()
        ->requireLowercase()
        ->requireNumber()
        ->requireSymbol();

    $rules = $field->buildRules();

    // Non-string rules (closures) are fine — we only assert the strings we add.
    $stringRules = array_values(array_filter($rules, 'is_string'));

    expect($stringRules)->toContain('min:10')
        ->and($stringRules)->toContain('regex:/[A-Z]/')
        ->and($stringRules)->toContain('regex:/[a-z]/')
        ->and($stringRules)->toContain('regex:/\d/')
        ->and($stringRules)->toContain('regex:/[^A-Za-z0-9]/');
});

it('Password::disallowCommonPasswords rejects a common value and passes a strong one', function () {
    $field = Password::make('password')->disallowCommonPasswords();
    $rules = $field->buildRules();
    $closures = array_values(array_filter($rules, fn ($r) => $r instanceof Closure));

    expect($closures)->not->toBeEmpty();
    $closure = $closures[count($closures) - 1];

    $failed = null;
    $closure('password', 'password123', function ($msg) use (&$failed) {
        $failed = $msg;
    });
    expect($failed)->toBeString();

    $failed = null;
    $closure('password', 'aB3!xYz9-qW', function ($msg) use (&$failed) {
        $failed = $msg;
    });
    expect($failed)->toBeNull();
});

it('Password::showRequirements emits the checklist flag and requirements map', function () {
    $field = Password::make('password')
        ->minLength(8)
        ->requireUppercase()
        ->requireNumber()
        ->showRequirements();

    $arr = $field->toArray();

    expect($arr)
        ->toHaveKey('showRequirements', true)
        ->toHaveKey('requirements');
    expect($arr['requirements'])
        ->toHaveKey('minLength', 8)
        ->toHaveKey('uppercase', true)
        ->toHaveKey('number', true)
        ->not->toHaveKey('symbol');
});

it('Password::toArray omits showRequirements and requirements when not configured', function () {
    $plain = Password::make('password')->toArray();

    expect($plain)
        ->not->toHaveKey('showRequirements')
        ->not->toHaveKey('requirements');
});

// ---------------------------------------------------------------------------
// PasswordConfirmation — companion behaviour
// ---------------------------------------------------------------------------

it('PasswordConfirmation is hidden from index and detail', function () {
    $field = PasswordConfirmation::make('password_confirmation');

    $arr = $field->toArray();
    expect($arr['showOnIndex'])->toBeFalse()
        ->and($arr['showOnDetail'])->toBeFalse();
});

it('PasswordConfirmation::confirms defaults to "password"', function () {
    $field = PasswordConfirmation::make('password_confirmation');

    expect($field->getConfirms())->toBe('password');
});

it('PasswordConfirmation::confirms() names the paired attribute', function () {
    $field = PasswordConfirmation::make('password_confirmation')->confirms('new_password');

    expect($field->getConfirms())->toBe('new_password');
});

it('PasswordConfirmation::fill never hydrates the model', function () {
    $model = new PasswordTestModel;
    $field = PasswordConfirmation::make('password_confirmation');

    $field->fill($model, 'anything');

    expect($model->getAttribute('password_confirmation'))->toBeNull();
});

it('PasswordConfirmation::resolve always returns null', function () {
    $model = new PasswordTestModel(['password' => 'whatever']);
    $field = PasswordConfirmation::make('password_confirmation');

    expect($field->resolve($model))->toBeNull();
});

it('PasswordConfirmation::toArray emits confirms', function () {
    $field = PasswordConfirmation::make('password_confirmation')->confirms('new_password');

    $arr = $field->toArray();

    expect($arr)
        ->toHaveKey('type', 'password_confirmation')
        ->toHaveKey('confirms', 'new_password');
});
