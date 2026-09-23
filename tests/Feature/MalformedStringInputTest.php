<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A parameter sent as an array where a string is expected answered 500
// (v1.38.0).
//
// `(string) $request->input(...)` on an array raises "Array to string
// conversion", which Laravel turns into an exception: `?context[]=x` on the
// action lists, `email[]=` / `token[]=` on the magic-link consume, `type[]=`
// on the cache admin endpoints and `email[]=` on any login route (the
// martis-login rate limiter reads it before any validation) and `range[]=`
// / `filters[]=` on a metric answered 500.
// Each is now read as a string or not at all.
// ===========================================================================

class MSIUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class MSIPost extends Model
{
    protected $table = 'msi_posts';

    protected $guarded = [];

    public $timestamps = false;
}

class MSIPostCountMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->count($request, MSIPost::class);
    }
}

class MSIPostResource extends Resource
{
    public static function model(): string
    {
        return MSIPost::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function cards(Request $request): array
    {
        return [MSIPostCountMetric::make('Posts', 'post-count')];
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
    Schema::dropIfExists('msi_posts');
    Schema::create('msi_posts', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MSIPostResource::class);
});

afterEach(function () {
    Schema::dropIfExists('msi_posts');
});

it('lists the actions of a resource when the context is not a string', function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    $this->getJson('/martis/api/resources/m-s-i-posts/actions?context[]=index')->assertOk();
});

it('answers the magic-link consume when the email or the token is not a string', function () {
    config()->set('martis.auth.magic_link.enabled', true);

    $this->get('/martis/api/auth/magic-link/consume?email[]=a@b.test&token=x')
        ->assertRedirect('/martis/login?magic_link=invalid');
    $this->get('/martis/api/auth/magic-link/consume?email=a@b.test&token[]=x')
        ->assertRedirect('/martis/login?magic_link=invalid');
});

it('answers the cache admin endpoints when the type is not a string', function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.admin_ui', true);
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));
    Gate::define('manage-martis-cache', fn () => true);
    $this->actingAs(MSIUser::query()->create(['name' => 'Admin', 'email' => 'admin@msi.test', 'password' => bcrypt('secret')]), 'web');

    foreach (['disable', 'enable', 'reset-override'] as $endpoint) {
        $this->postJson("/martis/api/cache/{$endpoint}", ['type' => ['schema']])->assertStatus(422);
    }
});

it('validates a login whose email is not a string instead of failing in the rate limiter', function () {
    $this->postJson('/martis/api/auth/login', ['email' => ['a@b.test'], 'password' => 'secret'])
        ->assertStatus(422);
});

it('computes a metric when its range or filters are not strings', function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    $this->getJson('/martis/api/resources/m-s-i-posts/cards/post-count?range[]=30')->assertOk();
    $this->getJson('/martis/api/resources/m-s-i-posts/cards/post-count?filters[]=x')->assertOk();
});
