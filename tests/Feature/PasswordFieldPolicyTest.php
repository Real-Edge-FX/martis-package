<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Martis\Fields\Password;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

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

it('shows a field requirement stricter than the policy (control)', function () {
    PasswordRule::defaults(fn () => PasswordRule::min(12));
    $field = Password::make('Password', 'password')->defaultRules()->minLength(16)->requireSymbol();

    expect($field->toArray()['requirements'])->toBe(['minLength' => 16, 'symbol' => true]);
});

/*
 * The server enforces the policy and the field together, so the checklist
 * shows the stricter of the two: a field minimum below the policy's used to
 * replace it, and the checklist ticked a length the server then refused.
 */
it('shows the policy minimum when the field asks for less, as the server enforces it', function () {
    PasswordRule::defaults(fn () => PasswordRule::min(12)->symbols());
    $field = Password::make('Password', 'password')->defaultRules()->showRequirements()->minLength(6);

    expect($field->toArray()['requirements'])->toBe(['minLength' => 12, 'symbol' => true])
        ->and(passwordFieldPasses($field, 'abcdefgh!'))->toBeFalse()
        ->and(passwordFieldPasses($field, 'abcdefghijk!'))->toBeTrue();
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

/*
 * The documented recipe for a resource that creates and updates users, as
 * Nova's User resource declares it: required on create, optional on update.
 * `->nullable()` alone, which the docs used to show, also made the password
 * optional on create.
 */
class PasswordRecipeUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class PasswordRecipeUserResource extends Resource
{
    public static function model(): string
    {
        return PasswordRecipeUser::class;
    }

    public static function uriKey(): string
    {
        return 'password-recipe-users';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            Text::make('email')->required(),
            Password::make('password')->defaultRules()->creationRules(['required'])->updateRules(['nullable']),
        ];
    }
}

function passwordRecipeResource(): void
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    test()->withoutMiddleware(MartisAuthenticate::class);
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PasswordRecipeUserResource::class);
    PasswordRule::defaults(fn () => PasswordRule::min(12));
}

it('requires a password on create with the documented recipe, and applies the policy', function () {
    passwordRecipeResource();

    // The resource API answers 422 with a list of { field, message, code }.
    $refused = fn ($response): array => collect($response->assertUnprocessable()->json('errors'))->pluck('code', 'field')->all();

    expect($refused($this->postJson('/martis/api/resources/password-recipe-users', ['name' => 'N', 'email' => 'n@example.com'])))
        ->toBe(['password' => 'required'])
        ->and($refused($this->postJson('/martis/api/resources/password-recipe-users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Short-1'])))
        ->toHaveKey('password');
    $this->postJson('/martis/api/resources/password-recipe-users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Long-Enough-1'])
        ->assertSuccessful();
    expect(Hash::check('Long-Enough-1', (string) PasswordRecipeUser::query()->value('password')))->toBeTrue();
});

it('keeps the current password when an update sends none, with the documented recipe', function () {
    passwordRecipeResource();
    $user = PasswordRecipeUser::query()->create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('Keep-Me-Please-1')]);

    $this->putJson("/martis/api/resources/password-recipe-users/{$user->id}", ['name' => 'U2', 'email' => 'u@example.com', 'password' => null])
        ->assertSuccessful();
    expect(Hash::check('Keep-Me-Please-1', (string) $user->fresh()->password))->toBeTrue();
});
