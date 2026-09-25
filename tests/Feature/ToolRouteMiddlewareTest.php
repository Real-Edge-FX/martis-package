<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Martis\Facades\Martis;
use Martis\Http\RouteMiddleware;
use Martis\Tools\Tool;
use Martis\Tools\ToolRoutes;
use Symfony\Component\Process\Process;

/*
 * The routes a Tool loads with `loadRoutes()` run the middleware of the
 * package's protected API routes (`martis.middleware`, the configured
 * `martis.auth_middleware`, the impersonation expiry, the 2FA challenge,
 * the user's locale, email verification and the API throttle), then the
 * tool's own gate, as Nova guards a tool's routes with its `Authorize`
 * middleware. Before v2.0 the default stack was `['web', 'martis.auth']`:
 * a user who had signed in with a password but not passed the 2FA
 * challenge, or had not verified the email the app requires, reached every
 * tool route.
 */

// ── Fixtures ────────────────────────────────────────────────────────────────

/** Loads the shared routes file with the default middleware. */
class ToolRouteDefaultTool extends Tool
{
    public function __construct(public string $routesPath)
    {
        parent::__construct(name: 'Default Routes', uriKey: 'tool-route-default');
    }

    public function boot(): void
    {
        $this->loadRoutes($this->routesPath);
    }
}

/** Loads the shared routes file with the middleware it names. */
class ToolRouteExplicitTool extends Tool
{
    public function __construct(public string $routesPath)
    {
        parent::__construct(name: 'Explicit Routes', uriKey: 'tool-route-explicit');
    }

    public function boot(): void
    {
        $this->loadRoutes($this->routesPath, ['web']);
    }
}

/** A tool no user may see, with default routes. */
class ToolRouteHiddenTool extends Tool
{
    public function __construct(public string $routesPath)
    {
        parent::__construct(name: 'Hidden Routes', uriKey: 'tool-route-hidden');
        $this->canSee(fn (Request $request) => false);
    }

    public function boot(): void
    {
        $this->loadRoutes($this->routesPath);
    }
}

/** Loads the shared routes file as its constructor says; one uriKey per test. */
class ToolRouteListTool extends Tool
{
    /** @param list<string>|null $middleware null: no middleware argument */
    public function __construct(string $uriKey, public string $routesPath, public ?array $middleware = null, public ?string $prefix = null)
    {
        parent::__construct(name: 'List '.$uriKey, uriKey: $uriKey);
    }

    public function boot(): void
    {
        if ($this->middleware !== null) {
            $this->loadRoutes($this->routesPath, $this->middleware, $this->prefix);
        } elseif ($this->prefix !== null) {
            // A named argument skips the middleware: the default applies.
            $this->loadRoutes($this->routesPath, prefix: $this->prefix);
        } else {
            $this->loadRoutes($this->routesPath);
        }
    }
}

/**
 * Overrides loadRoutes() with its v1.x signature and forwards to it, as a
 * tool written for v1.x may. Declared here, it also keeps the parent's
 * signature compatible: this file would not load otherwise.
 */
class ToolRouteLegacyOverrideTool extends Tool
{
    public function __construct(public string $routesPath)
    {
        parent::__construct(name: 'Legacy Override', uriKey: 'tool-route-legacy-override');
    }

    public function loadRoutes(string $path, array $middleware = ['web', 'martis.auth'], ?string $prefix = null): void
    {
        parent::loadRoutes($path, $middleware, $prefix);
    }

    public function boot(): void
    {
        $this->loadRoutes($this->routesPath);
    }
}

// ── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    $this->routesFile = tempnam(sys_get_temp_dir(), 'martis_tool_route_mw_');
    file_put_contents($this->routesFile, <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;

        Route::get('/ping', fn () => response()->json(['pong' => true]));
        PHP);

    Martis::tools([]);
});

afterEach(function () {
    Martis::tools([]);
    @unlink($this->routesFile);
});

/** Register the three tools and run their boot(), which loads their routes. */
function bootToolRouteTools(string $routesFile): void
{
    Martis::tools([
        new ToolRouteDefaultTool($routesFile),
        new ToolRouteExplicitTool($routesFile),
        new ToolRouteHiddenTool($routesFile),
    ]);
    Martis::getFacadeRoot()?->bootTools();
}

/** @param array<string, mixed> $attributes */
function toolRouteUser(array $attributes = []): User
{
    /** @var User $user */
    $user = User::forceCreate([
        'name' => 'Tool User',
        'email' => 'tool'.uniqid().'@example.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        ...$attributes,
    ]);

    return $user;
}

function toolRouteByUri(string $uri): RoutingRoute
{
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn (RoutingRoute $r) => $r->uri() === $uri);

    expect($route)->not->toBeNull("No route answers {$uri}.");

    return $route;
}

/** Register tools and run their boot(), which loads their routes. */
function bootToolRoutes(Tool ...$tools): void
{
    Martis::tools(array_values($tools));
    Martis::getFacadeRoot()?->bootTools();
}

/**
 * The warnings logged from now on that name the tool `$uriKey`.
 *
 * @return ArrayObject<int, string>
 */
function toolRouteWarnings(string $uriKey): ArrayObject
{
    /** @var ArrayObject<int, string> $warnings */
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings, $uriKey): void {
        if ($event->level === 'warning' && str_contains($event->message, "tool [{$uriKey}]")) {
            $warnings[] = $event->message;
        }
    });

    return $warnings;
}

// ── The same stack as the protected API routes ──────────────────────────────

it('gives a tool route the middleware of the protected API routes, then the tool gate', function () {
    bootToolRouteTools($this->routesFile);

    $api = toolRouteByUri('martis/api/tools')->gatherMiddleware();

    expect(toolRouteByUri('martis/api/tools/tool-route-default/ping')->gatherMiddleware())
        ->toBe([...$api, 'martis.tool:tool-route-default'])
        ->and($api)->toBe([
            'web',
            'martis.auth',
            'martis.impersonation.duration',
            'martis.2fa',
            'martis.locale',
            'martis.verified',
            'throttle:120,1,martis-api:web:',
        ]);
});

it('builds the tool route stack from martis.auth_middleware and martis.throttle.enabled', function () {
    config()->set('martis.auth_middleware', ['martis.auth', 'app.tenant']);
    config()->set('martis.throttle.enabled', false);
    bootToolRouteTools($this->routesFile);

    expect(toolRouteByUri('martis/api/tools/tool-route-default/ping')->gatherMiddleware())->toBe([
        'web',
        'martis.auth',
        'app.tenant',
        'martis.impersonation.duration',
        'martis.2fa',
        'martis.locale',
        'martis.verified',
        'martis.tool:tool-route-default',
    ]);
});

it('keeps an explicit middleware list as given', function () {
    bootToolRouteTools($this->routesFile);

    expect(toolRouteByUri('martis/api/tools/tool-route-explicit/ping')->gatherMiddleware())->toBe(['web']);

    // No 2FA gate on it: a pending challenge still reaches it, as before.
    $this->actingAs(toolRouteUser(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]), config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => false])
        ->getJson('/martis/api/tools/tool-route-explicit/ping')
        ->assertOk();
});

// ── What the stack refuses ──────────────────────────────────────────────────

it('answers a tool route as the package API when the 2FA challenge is pending', function () {
    bootToolRouteTools($this->routesFile);
    $this->actingAs(toolRouteUser(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]), config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => false]);

    $api = $this->getJson('/martis/api/tools')->assertStatus(423);
    $tool = $this->getJson('/martis/api/tools/tool-route-default/ping')->assertStatus(423);

    expect($tool->json())->toBe($api->json())
        ->and($tool->json('two_factor_required'))->toBeTrue();
});

it('lets a user who passed the 2FA challenge reach a tool route', function () {
    bootToolRouteTools($this->routesFile);
    $this->actingAs(toolRouteUser(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()]), config('martis.guard'))
        ->withSession(['martis_two_factor_passed' => true]);

    $this->getJson('/martis/api/tools')->assertOk();
    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertOk()->assertExactJson(['pong' => true]);
});

it('answers a tool route as the package API when the email the app requires is not verified', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    bootToolRouteTools($this->routesFile);
    $this->actingAs(toolRouteUser(['email_verified_at' => null]), config('martis.guard'));

    $api = $this->getJson('/martis/api/tools')->assertStatus(409);
    $tool = $this->getJson('/martis/api/tools/tool-route-default/ping')->assertStatus(409);

    expect($tool->json())->toBe($api->json());
});

it('answers a guest on a tool route as on the package API', function () {
    bootToolRouteTools($this->routesFile);

    $this->getJson('/martis/api/tools')->assertUnauthorized();
    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertUnauthorized();
});

it('applies the API throttle to a tool route', function () {
    config()->set('martis.throttle.max_attempts', 2);
    bootToolRouteTools($this->routesFile);
    $this->actingAs(toolRouteUser(), config('martis.guard'));

    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertOk();
    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertOk();
    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertStatus(429);
});

it('answers 404 on the routes of a tool the user cannot see, as on the tool itself', function () {
    bootToolRouteTools($this->routesFile);
    $this->actingAs(toolRouteUser(), config('martis.guard'));

    $tool = $this->getJson('/martis/api/tools/tool-route-hidden')->assertNotFound();
    $route = $this->getJson('/martis/api/tools/tool-route-hidden/ping')->assertNotFound();

    expect($route->json())->toBe($tool->json());
    // The gate names its own tool: the visible tool's route still answers.
    $this->getJson('/martis/api/tools/tool-route-default/ping')->assertOk();
});

// ── The config the stack is built from ──────────────────────────────────────

it('reads a middleware config value as a name or a list, null as the default', function () {
    config()->set('martis.auth_middleware', 'martis.auth');
    config()->set('martis.middleware', null);

    expect(RouteMiddleware::authenticated())->toBe(['martis.auth', 'martis.impersonation.duration'])
        ->and(RouteMiddleware::base())->toBe(['web']);

    config()->set('martis.auth_middleware', null);

    expect(RouteMiddleware::authenticated())->toBe(['martis.auth', 'martis.impersonation.duration']);
});

it('refuses a middleware config value that is neither a name nor a list of names', function (string $key, mixed $value) {
    config()->set($key, $value);

    expect(fn () => RouteMiddleware::api())->toThrow(InvalidArgumentException::class, "The [{$key}] config value must be a middleware name or a list of middleware names");
})->with([
    'auth_middleware as a number' => ['martis.auth_middleware', 42],
    'middleware with a number in the list' => ['martis.middleware', ['web', 1]],
    'auth_middleware with an empty name' => ['martis.auth_middleware', ['']],
]);

it('passes a throttle limit on as Laravel reads it, null as the default', function () {
    config()->set('martis.throttle.max_attempts', 'rate_limit');
    config()->set('martis.throttle.decay_minutes', null);

    // A name reads the limit from that attribute of the user.
    expect(RouteMiddleware::throttle())->toBe(['throttle:rate_limit,1,martis-api:web:']);
});

it('refuses a throttle limit that is not a number or an attribute name', function () {
    config()->set('martis.throttle.max_attempts', ['120']);

    expect(fn () => RouteMiddleware::throttle())->toThrow(InvalidArgumentException::class, 'The [martis.throttle.max_attempts] config value must be a number');
});

// ── A signature v1.x tools still load with ──────────────────────────────────

it('loads a tool that overrides loadRoutes() with the v1.x signature', function () {
    $script = tempnam(sys_get_temp_dir(), 'martis_legacy_tool_');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
        .' class LegacyLoadRoutesTool extends Martis\\Tools\\Tool {'
        .' public function loadRoutes(string $path, array $middleware = [\'web\', \'martis.auth\'], ?string $prefix = null): void'
        .' { parent::loadRoutes($path, $middleware, $prefix); } }'
        .' echo \'loaded\';');

    try {
        $process = new Process([PHP_BINARY, $script]);
        $process->run();

        // A narrower parameter type in the parent is a fatal error when the class loads.
        expect($process->getOutput().$process->getErrorOutput())->toBe('loaded')
            ->and($process->getExitCode())->toBe(0);
    } finally {
        @unlink($script);
    }
});

it('keeps the list a v1.x override forwards, and warns once naming the tool', function () {
    $warnings = toolRouteWarnings('tool-route-legacy-override');
    $tool = new ToolRouteLegacyOverrideTool($this->routesFile);
    bootToolRoutes($tool);
    $tool->loadRoutes($this->routesFile, prefix: 'martis/api/tools/tool-route-legacy-override/again');

    expect(toolRouteByUri('martis/api/tools/tool-route-legacy-override/ping')->gatherMiddleware())->toBe(['web', 'martis.auth'])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain(ToolRouteLegacyOverrideTool::class)
        ->toContain("with ['web', 'martis.auth'], the v1.x default of Tool::loadRoutes()")
        ->toContain('the 2FA challenge (martis.2fa)')
        ->toContain('Do not pass a middleware list');
});

it('warns about the v1.x list passed explicitly, even with 2FA and email verification off', function () {
    config()->set('martis.profile.two_factor.enabled', false);
    $warnings = toolRouteWarnings('tool-route-v1-list');
    bootToolRoutes(new ToolRouteListTool('tool-route-v1-list', $this->routesFile, ['web', 'martis.auth']));

    expect(toolRouteByUri('martis/api/tools/tool-route-v1-list/ping')->gatherMiddleware())->toBe(['web', 'martis.auth'])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain("the API throttle and the tool's canSee()")
        ->not->toContain('martis.2fa');
});

it('warns when a list leaves out the 2FA challenge while 2FA is on', function () {
    $warnings = toolRouteWarnings('tool-route-no-2fa');
    bootToolRoutes(new ToolRouteListTool('tool-route-no-2fa', $this->routesFile, ['web', 'martis.auth', 'throttle:60,1']));

    expect(toolRouteByUri('martis/api/tools/tool-route-no-2fa/ping')->gatherMiddleware())->toBe(['web', 'martis.auth', 'throttle:60,1'])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('leaves out the 2FA challenge (martis.2fa), which is on');
});

it('does not warn about a list without the 2FA challenge while 2FA is off', function () {
    config()->set('martis.profile.two_factor.enabled', false);
    $warnings = toolRouteWarnings('tool-route-2fa-off');
    bootToolRoutes(new ToolRouteListTool('tool-route-2fa-off', $this->routesFile, ['web', 'martis.auth', 'throttle:60,1']));

    expect($warnings)->toHaveCount(0);
});

it('warns when a list leaves out email verification while it is on', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    $warnings = toolRouteWarnings('tool-route-no-verified');
    bootToolRoutes(new ToolRouteListTool('tool-route-no-verified', $this->routesFile, ['web', 'martis.auth', 'martis.2fa']));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('leaves out email verification (martis.verified), which is on')
        ->not->toContain('martis.2fa)');
});

it('does not warn about a list that keeps the guards, through a group or the tool stack', function () {
    config()->set('martis.auth.email_verification.enabled', true);
    $group = toolRouteWarnings('tool-route-group');
    $stack = toolRouteWarnings('tool-route-stack');
    $stackTool = new ToolRouteListTool('tool-route-stack', $this->routesFile);
    $stackTool->middleware = [...ToolRoutes::middleware($stackTool), 'can:viewReports'];
    bootToolRoutes(new ToolRouteListTool('tool-route-group', $this->routesFile, ['martis.api', 'can:viewReports']), $stackTool);

    expect($group)->toHaveCount(0)->and($stack)->toHaveCount(0);
});

it('runs the tool stack by default, a named prefix included, without a warning', function () {
    $default = toolRouteWarnings('tool-route-default-stack');
    $named = toolRouteWarnings('tool-route-named-prefix');
    $defaultTool = new ToolRouteListTool('tool-route-default-stack', $this->routesFile);
    $namedTool = new ToolRouteListTool('tool-route-named-prefix', $this->routesFile, prefix: 'custom/tools/named');
    bootToolRoutes($defaultTool, $namedTool);

    expect(toolRouteByUri('martis/api/tools/tool-route-default-stack/ping')->gatherMiddleware())->toBe(ToolRoutes::middleware($defaultTool))
        ->and(toolRouteByUri('custom/tools/named/ping')->gatherMiddleware())->toBe(ToolRoutes::middleware($namedTool))
        ->and($default)->toHaveCount(0)
        ->and($named)->toHaveCount(0);
});

it('registers martis.api as the middleware group of the protected API routes', function () {
    expect(app('router')->getMiddlewareGroups()['martis.api'] ?? null)->toBe(RouteMiddleware::api())
        ->and(Tool::DEFAULT_ROUTE_MIDDLEWARE)->toBe(['martis.api']);
});

// ── The prefix follows martis.path ──────────────────────────────────────────

it('mounts a tool route under martis.path, where the SPA api client calls it', function () {
    config()->set('martis.path', 'admin');
    bootToolRoutes(new ToolRouteListTool('tool-route-admin-path', $this->routesFile));

    // The shell hands the api client /admin as its base path, and the client
    // prefixes every path with it: api.get('/api/tools/...').
    $this->get('/martis/login')->assertOk()->assertSee('basePath: "/admin"', false);

    $this->actingAs(toolRouteUser(), config('martis.guard'));
    $this->getJson('/admin/api/tools/tool-route-admin-path/ping')->assertOk()->assertExactJson(['pong' => true]);
    $this->getJson('/martis/api/tools/tool-route-admin-path/ping')->assertNotFound();
});

it('mounts a tool route under the default path, and keeps an explicit prefix', function () {
    bootToolRoutes(
        new ToolRouteListTool('tool-route-default-path', $this->routesFile),
        new ToolRouteListTool('tool-route-own-prefix', $this->routesFile, ['web'], 'hooks/own-prefix'),
    );

    expect(toolRouteByUri('martis/api/tools/tool-route-default-path/ping')->uri())->toBe('martis/api/tools/tool-route-default-path/ping')
        ->and(toolRouteByUri('hooks/own-prefix/ping')->gatherMiddleware())->toBe(['web']);
});
