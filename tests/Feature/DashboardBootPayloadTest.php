<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;

/*
 * The shell embeds the dashboards a user may see in its boot payload
 * (`window.MartisConfig.dashboards`, v2.19.0), the same list
 * `GET /api/dashboards` answers, so the SPA names the current dashboard
 * on its first render instead of "Dashboard" until the request answers.
 * The server-rendered <title> names the dashboard too.
 */

class BootPayloadTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class BootPayloadSupportDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Support', uriKey: 'support');
    }
}

class BootPayloadTeamDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Team', uriKey: 'support-team');
        $this->under('support');
    }
}

class BootPayloadHiddenDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Costs', uriKey: 'costs');
    }

    public function authorizedToSee(Request $request): bool
    {
        return false;
    }
}

class BootPayloadScriptNameDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Ops </script><script>alert(1)</script>', uriKey: 'ops');
    }
}

class BootPayloadThrowingDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Broken', uriKey: 'broken');
    }

    public function authorizedToSee(Request $request): bool
    {
        throw new RuntimeException('authorizedToSee failed');
    }
}

/** The `dashboards` value the shell hands the SPA. */
function shellDashboards(string $html): mixed
{
    expect(preg_match('/\bdashboards: (.*),\n/', $html, $match))->toBe(1);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

function shellTitle(string $html): string
{
    expect(preg_match('#<title>(.*)</title>#', $html, $match))->toBe(1);

    return html_entity_decode($match[1], ENT_QUOTES);
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

    $this->user = BootPayloadTestUser::create(['name' => 'Ada', 'email' => 'ada@boot.test', 'password' => 'unused']);
    $this->hotFile = public_path('vendor/martis/hot');
    File::ensureDirectoryExists(dirname($this->hotFile));
    File::put($this->hotFile, 'http://localhost:5173');
    Martis::dashboards([]);
    app(MartisCache::class)->clear('dashboards');
});

afterEach(function () {
    File::delete($this->hotFile);
    Martis::dashboards([]);
    app(MartisCache::class)->clear('dashboards');
});

it('embeds the dashboards the user may see, as the API answers them', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class, BootPayloadTeamDashboard::class, BootPayloadHiddenDashboard::class]);

    $seed = shellDashboards($this->actingAs($this->user)->get('/martis/dashboards/support-team')->assertOk()->getContent());
    $api = $this->actingAs($this->user)->getJson('/martis/api/dashboards')->assertOk()->json('data.dashboards');

    expect($seed)->toBe($api)
        ->and(array_column($seed, 'name'))->toBe(['Support', 'Team'])
        ->and($seed[1])->toMatchArray(['uriKey' => 'support-team', 'parent' => 'support', 'breadcrumb' => null]);
});

it('embeds the list on every page of the shell, not only on dashboards', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class]);

    $seed = shellDashboards($this->actingAs($this->user)->get('/martis/profile')->assertOk()->getContent());

    expect(array_column($seed, 'uriKey'))->toBe(['support']);
});

it('embeds an empty list for an install without dashboards', function () {
    expect(shellDashboards($this->actingAs($this->user)->get('/martis')->assertOk()->getContent()))->toBe([]);
});

it('embeds nothing on the pages served before sign-in', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class]);

    expect(shellDashboards($this->get('/martis/login')->assertOk()->getContent()))->toBeNull();
});

it('keeps a name holding a closing script tag inside the string', function () {
    Martis::dashboards([BootPayloadScriptNameDashboard::class]);

    $html = $this->actingAs($this->user)->get('/martis/dashboards/ops')->assertOk()->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(shellDashboards($html)[0]['name'])->toBe('Ops </script><script>alert(1)</script>');
});

it('serves the shell without the list when resolving the dashboards fails', function () {
    Martis::dashboards([BootPayloadThrowingDashboard::class]);

    $html = $this->actingAs($this->user)->get('/martis/profile')->assertOk()->getContent();

    expect(shellDashboards($html))->toBeNull();
});

it('names the dashboard in the server-rendered title', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class, BootPayloadTeamDashboard::class, BootPayloadHiddenDashboard::class]);
    $brand = config('martis.brand.name', 'Martis');

    expect(shellTitle($this->actingAs($this->user)->get('/martis/dashboards/support-team')->getContent()))->toBe("Team · {$brand}")
        ->and(shellTitle($this->actingAs($this->user)->get('/martis')->getContent()))->toBe("Support · {$brand}");
});

it('never guesses a dashboard name from its address', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class, BootPayloadHiddenDashboard::class]);

    $unknown = shellTitle($this->actingAs($this->user)->get('/martis/dashboards/support-team')->getContent());
    $hidden = shellTitle($this->actingAs($this->user)->get('/martis/dashboards/costs')->getContent());

    expect($unknown)->not->toContain('Support team')
        ->and($hidden)->not->toContain('Costs');
});

class BootPayloadNanMetaDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Nan', uriKey: 'nan');
    }

    public function meta(): array
    {
        return ['ratio' => NAN];
    }
}

it('embeds nothing on the 2FA challenge page, which the gates let every signed-in user reach', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class]);

    $html = $this->actingAs($this->user)->get('/martis/2fa/challenge')->assertOk()->getContent();

    expect(shellDashboards($html))->toBeNull()
        ->and(shellTitle($html))->not->toContain('Support');
});

it('embeds nothing and names no dashboard for a user viewMartis refuses', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class]);
    Gate::define('viewMartis', fn () => false);

    $panel = $this->actingAs($this->user)->get('/martis/dashboards/support')->assertForbidden()->getContent();
    $challenge = $this->actingAs($this->user)->get('/martis/2fa/challenge')->assertOk()->getContent();

    expect(shellDashboards($panel))->toBeNull()
        ->and(shellTitle($panel))->not->toContain('Support')
        ->and(shellDashboards($challenge))->toBeNull();
});

it('serves the shell without the list when a dashboard meta cannot be encoded', function () {
    Martis::dashboards([BootPayloadNanMetaDashboard::class]);

    $html = $this->actingAs($this->user)->get('/martis/profile')->assertOk()->getContent();

    expect(shellDashboards($html))->toBeNull();
});

it('never lets a shared cache keep the per-user shell', function () {
    Martis::dashboards([BootPayloadSupportDashboard::class]);

    $cacheControl = (string) $this->actingAs($this->user)->get('/martis')->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')->toContain('no-cache');
});
