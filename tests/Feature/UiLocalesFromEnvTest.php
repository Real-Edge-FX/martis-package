<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\UserPreference;
use Martis\Preferences\PreferencesResolver;

/*
 * v2.10.0: the UI locales and their labels come from the environment
 * (`MARTIS_UI_LOCALES`, `MARTIS_UI_LOCALE_LABELS`), and a stored or
 * default locale the app no longer offers is normalised.
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

/** Re-evaluate the package config file with the given env values. */
function evaluatedConfig(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        // env() reads through the repository the app booted with, which is
        // immutable: evaluate with a fresh adapter-free lookup.
        return (function () {
            return require __DIR__.'/../../config/martis.php';
        })();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

function actAsUiLocalesUser(): Authenticatable
{
    $user = (new Authenticatable)->forceFill(['id' => 7, 'name' => 'U', 'email' => 'u@test.local']);
    test()->actingAs($user);

    return $user;
}

it('offers the three bundled locales and their labels when nothing is configured', function () {
    $resolver = app(PreferencesResolver::class);

    expect($resolver->availableLocales())->toBe(['en', 'pt_PT', 'pt_BR'])
        ->and($resolver->localeLabels())->toMatchArray([
            'en' => 'English',
            'pt_PT' => 'Português (PT)',
            'pt_BR' => 'Português (BR)',
        ]);
});

it('reads MARTIS_UI_LOCALES and MARTIS_UI_LOCALE_LABELS from the environment', function () {
    $config = evaluatedConfig([
        'MARTIS_UI_LOCALES' => ' en_GB , ,pt_PT ',
        'MARTIS_UI_LOCALE_LABELS' => 'pt_PT:Português (Portugal)',
    ]);

    expect($config['preferences']['locales'])->toBe(['en_GB', 'pt_PT'])
        ->and($config['preferences']['locale_labels_env'])->toBe('pt_PT:Português (Portugal)')
        ->and($config['preferences']['locale_labels'])->toHaveKey('en_GB', 'English (UK)');
});

it('keeps the bundled default list when the environment sets nothing', function () {
    $config = evaluatedConfig([]);

    expect($config['preferences']['locales'])->toBe(['en', 'pt_PT', 'pt_BR'])
        ->and($config['preferences']['locale_labels_env'])->toBeNull();
});

it('lets the env labels override the config map', function () {
    config()->set('martis.preferences.locale_labels_env', 'pt_PT:Português (Portugal),en_GB:British English');

    $labels = app(PreferencesResolver::class)->localeLabels();

    expect($labels['pt_PT'])->toBe('Português (Portugal)')
        ->and($labels['en_GB'])->toBe('British English')
        ->and($labels['en'])->toBe('English');
});

it('lists exactly the configured locales in the preferences payload', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    actAsUiLocalesUser();

    $this->getJson('/martis/api/preferences')
        ->assertOk()
        ->assertJsonPath('meta.locales', ['en_GB', 'pt_PT']);
});

it('refuses to save a locale outside the configured list', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    actAsUiLocalesUser();

    $this->putJson('/martis/api/preferences', ['locale' => 'pt_BR'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'locale');

    $this->putJson('/martis/api/preferences', ['locale' => 'en_GB'])->assertOk();
    expect(UserPreference::where('user_id', 7)->value('locale'))->toBe('en_GB');
});

it('resolves a stored locale the app no longer offers to the default', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'en_GB');
    UserPreference::create(['user_id' => 7, 'locale' => 'pt_BR']);
    $request = Request::create('/martis', 'GET');
    $request->setUserResolver(fn () => actAsUiLocalesUser());

    expect(app(PreferencesResolver::class)->resolve($request)['locale'])->toBe('en_GB');
});

it('keeps a stored locale the app still offers', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'en_GB');
    UserPreference::create(['user_id' => 7, 'locale' => 'pt_PT']);
    $request = Request::create('/martis', 'GET');
    $request->setUserResolver(fn () => actAsUiLocalesUser());

    expect(app(PreferencesResolver::class)->resolve($request)['locale'])->toBe('pt_PT');
});

it('resolves a default locale outside the list to the first available one', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'fr');

    $resolver = app(PreferencesResolver::class);

    expect($resolver->configDefaults()['locale'])->toBe('en_GB')
        ->and($resolver->resolve(Request::create('/martis', 'GET'))['locale'])->toBe('en_GB');
});

it('normalises a preset locale outside the list', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'en_GB');
    config()->set('martis.preferences.presets', ['fr-preset' => ['locale' => 'fr'], 'pt-preset' => ['locale' => 'pt_PT']]);
    $resolver = app(PreferencesResolver::class);

    expect($resolver->resolve(Request::create('/martis', 'GET', ['preset' => 'fr-preset']))['locale'])->toBe('en_GB')
        ->and($resolver->resolve(Request::create('/martis', 'GET', ['preset' => 'pt-preset']))['locale'])->toBe('pt_PT');
});

it('serves English strings for en_GB through the fallback chain', function () {
    $english = $this->getJson('/martis/api/translations/en')->assertOk()->json();

    $this->getJson('/martis/api/translations/en_GB')
        ->assertOk()
        ->assertJson($english);
});

it('writes the normalised locale in the login shell html lang and boot payload', function () {
    config()->set('martis.preferences.enabled', true);
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'en_GB');

    $response = $this->get('/martis/login')->assertOk();

    $response->assertSee('<html lang="en-GB"', false);
    $response->assertSee('locale: "en_GB"', false);
    $response->assertSee('"locales":["en_GB","pt_PT"]', false);
    $response->assertSee('English (UK)', false);
});

it('applies the normalised locale to the request through the martis.locale middleware', function () {
    config()->set('martis.preferences.locales', ['en_GB', 'pt_PT']);
    config()->set('martis.preferences.defaults.locale', 'fr');
    $request = Request::create('/martis', 'GET');

    $response = app(ApplyUserPreferencesLocale::class)
        ->handle($request, fn () => response('ok'));

    expect(app()->getLocale())->toBe('en_GB')
        ->and($response->getContent())->toBe('ok');
});

it('throws naming MARTIS_UI_LOCALES when a configured code is not a locale', function (array $locales) {
    config()->set('martis.preferences.locales', $locales);

    expect(fn () => app(PreferencesResolver::class)->availableLocales())
        ->toThrow(InvalidArgumentException::class, 'MARTIS_UI_LOCALES');
})->with([[['en', 'not a locale']], [['en', '../etc']], [['en', 5]]]);

it('throws naming MARTIS_UI_LOCALE_LABELS when the env labels are malformed', function () {
    config()->set('martis.preferences.locale_labels_env', 'en_GB English');

    expect(fn () => app(PreferencesResolver::class)->localeLabels())
        ->toThrow(InvalidArgumentException::class, 'MARTIS_UI_LOCALE_LABELS');
});

it('deduplicates the configured locales', function () {
    config()->set('martis.preferences.locales', ['en', 'en', 'pt_PT']);

    expect(app(PreferencesResolver::class)->availableLocales())->toBe(['en', 'pt_PT']);
});
