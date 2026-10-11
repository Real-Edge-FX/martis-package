<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ViewException;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\UserPreference;
use Martis\Preferences\PreferencesResolver;

/*
 * v2.12.0: `martis.preferences.editable` (MARTIS_PREFERENCES_EDITABLE) names
 * the preferences users may change. One left out is refused by the PUT when
 * the request changes it, ignored in a stored row and in a URL preset, and
 * resolves to its default. `theme.allowToggle => false` drops `theme`, and
 * the brand-colour hex follows `accent` and `allowBrandColor`.
 */

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('martis_user_preferences')) {
        Schema::create('martis_user_preferences', function ($t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('theme')->default('dark');
            $t->string('accent')->default('martis');
            $t->string('brand_color')->nullable();
            $t->string('density')->default('comfortable');
            $t->string('locale')->default('en');
            $t->boolean('reduced_motion')->default(false);
            $t->timestamps();
        });
    }
});

afterEach(function () {
    UserPreference::query()->delete();
});

/** Evaluate the package config file with MARTIS_PREFERENCES_EDITABLE set (or unset with null). */
function editableFromEnv(?string $value): mixed
{
    $key = 'MARTIS_PREFERENCES_EDITABLE';
    $previous = getenv($key);
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    } else {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        return (function () {
            return require __DIR__.'/../../config/martis.php';
        })()['preferences']['editable'];
    } finally {
        if ($previous === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$previous}");
            $_ENV[$key] = $previous;
            $_SERVER[$key] = $previous;
        }
    }
}

function actAsEditableUser(): Authenticatable
{
    $user = (new Authenticatable)->forceFill(['id' => 11, 'name' => 'U', 'email' => 'u@test.local']);
    test()->actingAs($user);

    return $user;
}

function storeEditablePrefs(array $attrs): void
{
    UserPreference::create(array_merge([
        'user_id' => 11,
        'theme' => 'light',
        'accent' => 'teal',
        'density' => 'dense',
        'locale' => 'pt_PT',
        'reduced_motion' => true,
    ], $attrs));
}

it('defaults to every preference when the variable is unset', function () {
    expect(editableFromEnv(null))->toBe(['theme', 'accent', 'density', 'locale', 'reducedMotion']);
});

it('reads MARTIS_PREFERENCES_EDITABLE, trimming blanks; empty is unset and none locks everything', function () {
    expect(editableFromEnv(' theme , ,density,reducedMotion '))->toBe(['theme', 'density', 'reducedMotion'])
        ->and(editableFromEnv(''))->toBe(['theme', 'accent', 'density', 'locale', 'reducedMotion'])
        ->and(editableFromEnv('none'))->toBe([]);
});

it('throws when the variable is set but the published config has no editable key', function () {
    config()->set('martis.preferences.editable', null);
    putenv('MARTIS_PREFERENCES_EDITABLE=theme,density');
    $_ENV['MARTIS_PREFERENCES_EDITABLE'] = $_SERVER['MARTIS_PREFERENCES_EDITABLE'] = 'theme,density';

    try {
        expect(fn () => app(PreferencesResolver::class)->editable())
            ->toThrow(InvalidArgumentException::class, 'MARTIS_PREFERENCES_EDITABLE is set but the published config/martis.php has no `preferences.editable` key');
    } finally {
        putenv('MARTIS_PREFERENCES_EDITABLE');
        unset($_ENV['MARTIS_PREFERENCES_EDITABLE'], $_SERVER['MARTIS_PREFERENCES_EDITABLE']);
    }
});

it('treats a config published before v2.12.0 (no key) as everything editable', function () {
    config()->set('martis.preferences.editable', null);

    expect(app(PreferencesResolver::class)->editable())->toBe(['theme', 'accent', 'density', 'locale', 'reducedMotion']);
});

it('drops theme when theme.allowToggle is false and adds brandColor with accent and allowBrandColor', function () {
    config()->set('martis.preferences.editable', ['theme', 'accent', 'density']);
    config()->set('martis.theme.allowToggle', false);
    config()->set('martis.preferences.allowBrandColor', true);

    expect(app(PreferencesResolver::class)->editable())->toBe(['accent', 'density', 'brandColor']);

    config()->set('martis.preferences.editable', ['density']);
    expect(app(PreferencesResolver::class)->editable())->toBe(['density']);
});

it('throws naming the variable for an unknown preference', function () {
    config()->set('martis.preferences.editable', ['theme', 'colour']);

    app(PreferencesResolver::class)->editable();
})->throws(InvalidArgumentException::class, 'MARTIS_PREFERENCES_EDITABLE (martis.preferences.editable): unknown preference "colour"');

it('explains that brandColor follows accent when it is listed', function () {
    config()->set('martis.preferences.editable', ['brandColor']);

    app(PreferencesResolver::class)->editable();
})->throws(InvalidArgumentException::class, 'brandColor follows accent and preferences.allowBrandColor');

it('throws when the value is not a list', function () {
    config()->set('martis.preferences.editable', 'theme,accent');

    app(PreferencesResolver::class)->editable();
})->throws(InvalidArgumentException::class, 'expected a list of preference names, got string');

it('reports the effective list in meta.editable', function () {
    config()->set('martis.preferences.editable', ['theme', 'density', 'reducedMotion']);
    actAsEditableUser();

    $this->getJson('/martis/api/preferences')
        ->assertOk()
        ->assertJsonPath('meta.editable', ['theme', 'density', 'reducedMotion']);
});

it('refuses a PUT that changes a locked accent and stores nothing', function () {
    config()->set('martis.preferences.editable', ['theme', 'density', 'reducedMotion']);
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['accent' => 'teal', 'density' => 'dense'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'accent');

    expect(UserPreference::query()->count())->toBe(0);
});

it('accepts a full-state PUT whose locked values match the ones in effect', function () {
    config()->set('martis.preferences.editable', ['theme', 'density', 'reducedMotion']);
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', [
        'theme' => 'light',
        'accent' => 'martis',
        'brandColor' => null,
        'density' => 'dense',
        'locale' => 'en',
        'reducedMotion' => false,
    ])->assertOk()
        ->assertJsonPath('data.theme', 'light')
        ->assertJsonPath('data.density', 'dense')
        ->assertJsonPath('data.accent', 'martis');
});

it('resolves a locked preference stored earlier to its default, keeping the editable ones', function () {
    config()->set('martis.preferences.editable', ['theme', 'density', 'reducedMotion']);
    config()->set('martis.preferences.defaults.accent', 'violet');
    storeEditablePrefs([]);
    actAsEditableUser();

    $this->getJson('/martis/api/preferences')
        ->assertOk()
        ->assertJsonPath('data.accent', 'violet')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.theme', 'light')
        ->assertJsonPath('data.density', 'dense')
        ->assertJsonPath('data.reducedMotion', true);
});

it('applies a preset without its locked accent', function () {
    config()->set('martis.preferences.editable', ['theme', 'density', 'reducedMotion']);
    config()->set('martis.preferences.defaults.theme', 'dark');
    config()->set('martis.preferences.presets.focus-light', ['theme' => 'light', 'accent' => 'amber', 'brandColor' => '#112233']);

    $prefs = app(PreferencesResolver::class)->resolve(Request::create('/martis', 'GET', ['preset' => 'focus-light']));

    expect($prefs['source'])->toBe('preset')
        ->and($prefs['theme'])->toBe('light')
        ->and($prefs['accent'])->toBe('martis')
        ->and($prefs['brandColor'])->toBeNull();
});

it('keeps the app language when the locale is locked, whatever the stored row says', function () {
    config()->set('martis.preferences.editable', ['theme']);
    config()->set('martis.preferences.defaults.locale', 'en');
    storeEditablePrefs([]);
    $user = actAsEditableUser();
    $request = Request::create('/martis', 'GET');
    $request->setUserResolver(fn () => $user);

    app(ApplyUserPreferencesLocale::class)->handle($request, fn () => response('ok'));

    expect(app()->getLocale())->toBe('en');
});

it('starts a new row from the values in effect, not the schema defaults, for a partial PUT', function () {
    config()->set('martis.preferences.editable', ['density']);
    config()->set('martis.preferences.defaults.accent', 'violet');
    config()->set('martis.preferences.defaults.locale', 'pt_PT');
    config()->set('martis.preferences.defaults.theme', 'light');
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['density' => 'dense'])->assertOk();

    $row = UserPreference::query()->firstOrFail();
    expect($row->accent)->toBe('violet')
        ->and($row->locale)->toBe('pt_PT')
        ->and($row->theme->value)->toBe('light')
        ->and($row->density->value)->toBe('dense');
});

it('lets an accent swatch clear a default hex while allowBrandColor is off (v2.11.1 behaviour)', function () {
    config()->set('martis.preferences.allowBrandColor', false);
    config()->set('martis.preferences.defaults.brandColor', '#123456');
    actAsEditableUser();

    $this->getJson('/martis/api/preferences')->assertJsonPath('data.brandColor', '#123456');

    $this->putJson('/martis/api/preferences', ['accent' => 'teal', 'brandColor' => null])
        ->assertOk()
        ->assertJsonPath('data.accent', 'teal')
        ->assertJsonPath('data.brandColor', null);

    $this->getJson('/martis/api/preferences')->assertJsonPath('data.brandColor', null);
});

it('keeps a default hex when another preference creates the row', function () {
    config()->set('martis.preferences.allowBrandColor', false);
    config()->set('martis.preferences.defaults.brandColor', '#123456');
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['density' => 'dense'])
        ->assertOk()
        ->assertJsonPath('data.brandColor', '#123456');
});

it('refuses brandColor null when the accent itself is locked and a default hex applies', function () {
    config()->set('martis.preferences.editable', ['density']);
    config()->set('martis.preferences.defaults.brandColor', '#123456');
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['brandColor' => null])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'brandColor');
});

it('keeps v2.11.1 behaviour when every preference is editable', function () {
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['accent' => 'teal', 'locale' => 'pt_PT'])
        ->assertOk()
        ->assertJsonPath('data.accent', 'teal')
        ->assertJsonPath('data.locale', 'pt_PT')
        ->assertJsonPath('meta.editable', ['theme', 'accent', 'density', 'locale', 'reducedMotion']);

    $prefs = app(PreferencesResolver::class)->resolve(Request::create('/martis', 'GET', ['preset' => 'focus-amber']));
    expect($prefs['accent'])->toBe('amber');
});

it('refuses a theme change and ignores a stored theme when theme.allowToggle is false', function () {
    config()->set('martis.theme.allowToggle', false);
    config()->set('martis.preferences.defaults.theme', 'dark');
    storeEditablePrefs([]);
    actAsEditableUser();

    $this->getJson('/martis/api/preferences')->assertJsonPath('data.theme', 'dark');
    $this->putJson('/martis/api/preferences', ['theme' => 'light'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'theme');
});

it('enforces allowBrandColor on the server: a hex is refused and a stored one ignored', function () {
    config()->set('martis.preferences.allowBrandColor', false);
    storeEditablePrefs(['accent' => 'custom', 'brand_color' => '#112233']);
    actAsEditableUser();

    $this->getJson('/martis/api/preferences')
        ->assertJsonPath('data.brandColor', null)
        ->assertJsonPath('data.accent', 'martis');

    $this->putJson('/martis/api/preferences', ['brandColor' => '#445566'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'brandColor');

    // Clearing it (what a full-state client sends) is still accepted.
    $this->putJson('/martis/api/preferences', ['brandColor' => null, 'accent' => 'blue'])
        ->assertOk()
        ->assertJsonPath('data.accent', 'blue');
});

it('accepts a hex when allowBrandColor is on and accent is editable', function () {
    config()->set('martis.preferences.allowBrandColor', true);
    actAsEditableUser();

    $this->putJson('/martis/api/preferences', ['brandColor' => '#445566', 'accent' => 'custom'])
        ->assertOk()
        ->assertJsonPath('data.brandColor', '#445566')
        ->assertJsonPath('data.accent', 'custom');
});

it('bootstraps the effective list into the shell payload', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.editable', ['theme', 'density']);

    $this->get('/martis/login')
        ->assertOk()
        ->assertSee('"editable":["theme","density"]', false);
});

it('does not read the list in the shell when the preferences are off', function () {
    config()->set('martis.preferences.enabled', false);
    config()->set('martis.preferences.editable', ['nope']);

    $this->get('/martis/login')->assertOk();
});

it('fails loudly in the shell for an invalid list', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.editable', ['nope']);

    $this->withoutExceptionHandling();
    $this->get('/martis/login');
})->throws(ViewException::class, 'MARTIS_PREFERENCES_EDITABLE (martis.preferences.editable): unknown preference "nope"');
