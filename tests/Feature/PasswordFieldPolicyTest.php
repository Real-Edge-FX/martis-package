<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Martis\Fields\Password;

// The resource Password field (v2.3.0): `defaultRules()` validates with the
// app's Password::defaults() and shows its requirements; the declarative
// requirements check what Laravel's Password rule checks (Unicode classes).

beforeEach(fn () => PasswordRule::$defaultCallback = null);
afterEach(fn () => PasswordRule::$defaultCallback = null);

function passwordFieldPasses(Password $field, mixed $value): bool
{
    return Validator::make(['password' => $value], ['password' => $field->buildRules()])->passes();
}

it('validates with the app policy and shows its requirements', function () {
    PasswordRule::defaults(fn () => PasswordRule::min(12)->mixedCase());
    $field = Password::make('Password', 'password')->defaultRules()->showRequirements();

    expect($field->toArray()['requirements'])->toBe(['minLength' => 12, 'uppercase' => true, 'lowercase' => true])
        ->and(passwordFieldPasses($field, 'Abcdefghijk'))->toBeFalse()
        ->and(passwordFieldPasses($field, 'Abcdefghijkl'))->toBeTrue();
});

it('lets a requirement set on the field win over the policy on the same key', function () {
    PasswordRule::defaults(fn () => PasswordRule::min(12));
    $field = Password::make('Password', 'password')->defaultRules()->minLength(16)->requireSymbol();

    expect($field->toArray()['requirements'])->toBe(['minLength' => 16, 'symbol' => true]);
});

it('keeps the current password when an update leaves a nullable field blank', function () {
    PasswordRule::defaults(fn () => PasswordRule::min(12));
    $field = Password::make('Password', 'password')->nullable()->defaultRules();

    expect(passwordFieldPasses($field, null))->toBeTrue();
});

it('checks Unicode letters, numbers and symbols as Laravel does', function () {
    $upper = Password::make('Password', 'password')->requireUppercase();
    $number = Password::make('Password', 'password')->requireNumber();
    $symbol = Password::make('Password', 'password')->requireSymbol();

    expect(passwordFieldPasses($upper, 'éclairÉ'))->toBeTrue()
        ->and(passwordFieldPasses($number, 'abc٣'))->toBeTrue()
        // An accented letter is not a symbol (the old /[^A-Za-z0-9]/ said it was).
        ->and(passwordFieldPasses($symbol, 'éclair'))->toBeFalse()
        ->and(passwordFieldPasses($symbol, 'éclair€'))->toBeTrue();
});
