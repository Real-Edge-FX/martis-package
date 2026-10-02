<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\PanelAccess;
use Martis\Auth\PolicyCoverage;

/*
 * `viewMartis` decides who may open the panel at all (v2.1.0). Defined, it
 * guards the shell, the protected API and the Tool routes that run the
 * Martis API stack (ToolRoutes::middleware), in every environment. Not
 * defined, the panel is open to every signed-in user only in the
 * environments of `martis.panel_access.open_environments` (v2.4.0: `local`
 * and `testing`, as Nova's viewNova does with `local`) and shut anywhere
 * else.
 */

class PanelAccessTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class PanelAccessPolicylessResource extends Martis\Resource
{
    public static function model(): string
    {
        return PanelAccessTestUser::class;
    }

    public function fields(Illuminate\Http\Request $request): array
    {
        return [];
    }
}

class PanelAccessUnauthorizableResource extends PanelAccessPolicylessResource
{
    public static function authorizable(): bool
    {
        return false;
    }
}

/** A policy of the user model: it must not decide the panel gate. */
class PanelAccessTestUserPolicy
{
    public function viewMartis(PanelAccessTestUser $user): bool
    {
        return true;
    }
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->admin = PanelAccessTestUser::create(['name' => 'Admin', 'email' => 'admin@panel.test', 'password' => 'unused']);
    $this->member = PanelAccessTestUser::create(['name' => 'Member', 'email' => 'member@panel.test', 'password' => 'unused']);

    PanelAccess::flushWarnings();
    PolicyCoverage::flush();
});

function allowOnlyPanelAdmins(): void
{
    Gate::define(PanelAccess::GATE, fn (PanelAccessTestUser $user) => $user->email === 'admin@panel.test');
}

it('lets every signed-in user in while the app defines no viewMartis gate, in the testing environment', function () {
    $this->actingAs($this->member)->get('/martis')->assertOk();
    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();
});

it('lets a user the gate allows in', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->admin)->get('/martis')->assertOk();
    $this->actingAs($this->admin)->getJson('/martis/api/command-palette')->assertOk();
});

it('answers 403 with a message to an API request of a user the gate refuses', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')
        ->assertForbidden()
        ->assertJsonPath('message', __('martis::messages.panel_forbidden'));
});

it('answers 403 with the no-access shell to a page request of a user the gate refuses', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->get('/martis/resources/users')
        ->assertForbidden()
        ->assertSee('panelForbidden: true', false);
});

it('marks the shell of an allowed user as not forbidden', function () {
    $this->actingAs($this->member)->get('/martis')->assertSee('panelForbidden: false', false);
});

it('lets a refused user reach the 2FA challenge page, and refuses the rest of the shell', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->get('/martis/2fa/challenge')
        ->assertOk()
        ->assertSee('panelForbidden: false', false);

    $this->actingAs($this->member)->get('/martis')->assertForbidden();
    $this->actingAs($this->member)->get('/martis/2fa/challenge/extra')->assertForbidden();
});

it('lets a refused user sign out', function () {
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->postJson('/martis/api/auth/logout')->assertSuccessful();

    $this->assertGuest();
});

it('applies Gate::before callbacks', function () {
    allowOnlyPanelAdmins();
    Gate::before(fn (PanelAccessTestUser $user, string $ability) => $ability === PanelAccess::GATE && $user->email === 'member@panel.test' ? true : null);

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();
});

it('is not decided by a policy of the user model', function () {
    Gate::policy(PanelAccessTestUser::class, PanelAccessTestUserPolicy::class);
    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertForbidden();
});

it('guards the shell and the API, and leaves the sign-in routes alone', function () {
    Route::getRoutes()->refreshNameLookups();
    $middleware = function (string $name): array {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} is missing");

        return $route->gatherMiddleware();
    };

    expect($middleware('martis.spa'))->toContain('martis.authorize')
        ->and($middleware('martis.api.command-palette'))->toContain('martis.authorize');

    foreach (['martis.login', 'martis.logout', 'martis.api.auth.logout', 'martis.api.2fa.challenge', 'martis.email.verify.notice', 'martis.api.translations.show', 'martis.api.auth.user'] as $name) {
        expect($middleware($name))->not->toContain('martis.authorize');
    }
});

it('answers allows() for a guest only while the gate is undefined', function () {
    expect(PanelAccess::allows(null))->toBeTrue();

    allowOnlyPanelAdmins();

    expect(PanelAccess::allows(null))->toBeFalse()
        ->and(PanelAccess::allows($this->member))->toBeFalse()
        ->and(PanelAccess::allows($this->admin))->toBeTrue();
});

it('tells the SPA whether the signed-in user may open the panel', function () {
    $this->actingAs($this->member)->getJson('/martis/api/auth/user')->assertOk()->assertJsonPath('panel_access', true);

    allowOnlyPanelAdmins();

    $this->actingAs($this->member)->getJson('/martis/api/auth/user')->assertJsonPath('panel_access', false);
    $this->actingAs($this->admin)->getJson('/martis/api/auth/user')->assertJsonPath('panel_access', true);
});

it('tells the SPA on sign-in whether the user may open the panel', function () {
    config()->set('auth.providers.users.model', PanelAccessTestUser::class);
    $this->member->forceFill(['password' => Hash::make('member-secret')])->save();
    allowOnlyPanelAdmins();

    $this->postJson('/martis/api/auth/login', ['email' => 'member@panel.test', 'password' => 'member-secret'])
        ->assertOk()
        ->assertJsonPath('panel_access', false);
});

it('runs the forced password change last, and keeps its own page and endpoint out of it', function () {
    Route::getRoutes()->refreshNameLookups();
    $middleware = fn (string $name): array => Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($middleware('martis.spa'))->toContain('martis.password.changed')
        ->and($middleware('martis.api.command-palette'))->toContain('martis.password.changed');

    foreach (['martis.password.change', 'martis.api.auth.password.change'] as $name) {
        expect($middleware($name))->toContain('martis.authorize')->not->toContain('martis.password.changed');
    }
});

// ---------------------------------------------------------------------------
// v2.4.0: no gate means open only in the local environments (F019)
// ---------------------------------------------------------------------------

/** Run the app as if it were deployed in $environment. */
function panelRunsIn(string $environment): void
{
    app()['env'] = $environment;
}

it('shuts the panel to every signed-in user outside the open environments while no gate is defined', function (string $environment) {
    panelRunsIn($environment);

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')
        ->assertForbidden()
        ->assertJsonPath('message', __('martis::messages.panel_forbidden'));
    $this->actingAs($this->admin)->get('/martis/resources/users')
        ->assertForbidden()
        ->assertSee('panelForbidden: true', false);
    $this->actingAs($this->member)->getJson('/martis/api/tools')->assertForbidden();
})->with(['production' => ['production'], 'staging' => ['staging'], 'an unnamed one' => ['qa']]);

it('keeps the panel open in local and testing while no gate is defined', function (string $environment) {
    panelRunsIn($environment);

    $this->actingAs($this->member)->get('/martis')->assertOk();
    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();
})->with(['local' => ['local'], 'testing' => ['testing']]);

it('lets a user the gate allows in, and refuses one it denies, in every environment', function (string $environment) {
    panelRunsIn($environment);
    allowOnlyPanelAdmins();

    $this->actingAs($this->admin)->getJson('/martis/api/command-palette')->assertOk();
    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertForbidden();
})->with(['production' => ['production'], 'local' => ['local'], 'testing' => ['testing']]);

it('answers allows() by the environment while the gate is undefined, for a guest too', function () {
    panelRunsIn('production');

    expect(PanelAccess::allows(null))->toBeFalse()
        ->and(PanelAccess::allows($this->admin))->toBeFalse()
        ->and(PanelAccess::enforced())->toBeTrue();

    panelRunsIn('local');

    expect(PanelAccess::allows(null))->toBeTrue()
        ->and(PanelAccess::allows($this->admin))->toBeTrue()
        ->and(PanelAccess::enforced())->toBeFalse();

    allowOnlyPanelAdmins();

    expect(PanelAccess::enforced())->toBeTrue();
});

it('reads the open environments from martis.panel_access.open_environments', function () {
    panelRunsIn('staging');

    config()->set('martis.panel_access.open_environments', ['local', 'staging']);
    expect(PanelAccess::allows($this->member))->toBeTrue();
    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertOk();

    // Closing `testing` shuts the suite of the app too.
    panelRunsIn('testing');
    config()->set('martis.panel_access.open_environments', ['local']);
    expect(PanelAccess::allows($this->member))->toBeFalse();

    config()->set('martis.panel_access.open_environments', []);
    panelRunsIn('local');
    expect(PanelAccess::allows($this->member))->toBeFalse();
});

it('accepts the open environments as a comma-separated string, and falls back to local and testing', function () {
    config()->set('martis.panel_access.open_environments', ' local , staging,,');
    expect(PanelAccess::openEnvironments())->toBe(['local', 'staging']);

    config()->set('martis.panel_access.open_environments', null);
    expect(PanelAccess::openEnvironments())->toBe(['local', 'testing']);

    config()->set('martis.panel_access.open_environments', 42);
    expect(PanelAccess::openEnvironments())->toBe(['local', 'testing']);
});

it('ships local and testing as the default open environments, and reads the env var', function () {
    expect(config('martis.panel_access.open_environments'))->toBe(['local', 'testing']);

    putenv('MARTIS_PANEL_OPEN_ENVIRONMENTS=local, staging');
    try {
        $config = require __DIR__.'/../../config/martis.php';
    } finally {
        putenv('MARTIS_PANEL_OPEN_ENVIRONMENTS');
    }

    expect($config['panel_access']['open_environments'])->toBe(['local', 'staging']);
});

it('lets a refused user sign out while the panel is shut for want of a gate', function () {
    panelRunsIn('production');

    // Outside `testing` the framework checks the CSRF token.
    $this->withSession(['_token' => 'csrf'])->actingAs($this->member)
        ->postJson('/martis/api/auth/logout', [], ['X-CSRF-TOKEN' => 'csrf'])
        ->assertSuccessful();

    $this->assertGuest();
});

it('tells the SPA the user may not open a panel that is shut for want of a gate', function () {
    panelRunsIn('production');

    $this->actingAs($this->member)->getJson('/martis/api/auth/user')->assertOk()->assertJsonPath('panel_access', false);
});

it('logs why the panel refused, once, not on every request', function () {
    panelRunsIn('production');
    $warnings = [];
    Log::listen(function ($event) use (&$warnings) {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertForbidden();
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('defines no `viewMartis` gate')->toContain('[production]')->toContain('martis.panel_access.open_environments');
});

it('does not log a refusal the gate itself made', function () {
    panelRunsIn('production');
    allowOnlyPanelAdmins();
    $warnings = [];
    Log::listen(function ($event) use (&$warnings) {
        $warnings[] = $event->message;
    });

    $this->actingAs($this->member)->getJson('/martis/api/command-palette')->assertForbidden();

    expect($warnings)->toBe([]);
});

it('logs a resource without a policy outside the open environments, once a day, and keeps it permissive', function () {
    panelRunsIn('production');
    allowOnlyPanelAdmins();
    $warnings = [];
    Log::listen(function ($event) use (&$warnings) {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    foreach (range(1, 3) as $_) {
        expect(PanelAccessPolicylessResource::resolvePolicy())->toBeNull();
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain(PanelAccessPolicylessResource::class)->toContain('has no policy');

    // The next process of the day finds the cache entry: nothing more is logged.
    PolicyCoverage::flush();
    PanelAccessPolicylessResource::resolvePolicy();
    expect($warnings)->toHaveCount(1);

    $request = Illuminate\Http\Request::create('/');
    $request->setUserResolver(fn () => $this->member);
    expect((new PanelAccessPolicylessResource)->authorizedToViewAny($request))->toBeTrue()
        ->and((new PanelAccessPolicylessResource)->authorizedToDelete($request))->toBeTrue();
});

it('does not log a resource without a policy in the open environments, or one that is not authorizable', function () {
    $warnings = [];
    Log::listen(function ($event) use (&$warnings) {
        $warnings[] = $event->message;
    });

    foreach (['local', 'testing'] as $environment) {
        panelRunsIn($environment);
        PolicyCoverage::flush();
        PanelAccessPolicylessResource::resolvePolicy();
    }

    panelRunsIn('production');
    PolicyCoverage::flush();
    PanelAccessUnauthorizableResource::resolvePolicy();

    expect($warnings)->toBe([]);
});
