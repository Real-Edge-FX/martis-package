<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Auth\Listeners\RecordAuthorizationDenial;
use Martis\Authorization\RequestScopedAbilityCache;
use Martis\Models\ActionEvent;
use Martis\Testing\AssertsAuthorization;

uses(AssertsAuthorization::class);

class AuthzTestUser extends User
{
    protected $table = 'authz_users';

    protected $guarded = [];

    public $timestamps = false;
}

class AuthzTestPost extends Model
{
    protected $table = 'authz_posts';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::dropIfExists('authz_users');
    Schema::create('authz_users', function ($t) {
        $t->id();
        $t->string('email');
    });
    Schema::dropIfExists('authz_posts');
    Schema::create('authz_posts', function ($t) {
        $t->id();
        $t->string('title');
        $t->boolean('is_admin_only')->default(false);
    });

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->unsignedBigInteger('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->unsignedBigInteger('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    Gate::define('view-test-post', function ($user, AuthzTestPost $post): bool {
        return ! $post->is_admin_only;
    });
});

afterEach(function () {
    Schema::dropIfExists('authz_users');
    Schema::dropIfExists('authz_posts');
    Schema::dropIfExists('martis_action_events');
});

// -----------------------------------------------------------------------------
// C1 — RecordAuthorizationDenial listener
// -----------------------------------------------------------------------------

it('audit denials listener writes a row when fed a denied GateEvaluated', function () {
    config()->set('martis.audit.authz_denials', true);

    $user = AuthzTestUser::create(['email' => 'a@example.com']);
    $post = AuthzTestPost::create(['title' => 'admin', 'is_admin_only' => true]);

    $listener = new RecordAuthorizationDenial;
    $listener->handle(new \Illuminate\Auth\Access\Events\GateEvaluated(
        $user,
        'view-test-post',
        false,
        [$post],
    ));

    $row = ActionEvent::query()->where('name', 'authz.denied')->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->fields['ability'])->toBe('view-test-post');
    expect($row->fields['model_class'])->toBe(AuthzTestPost::class);
    expect($row->fields['model_id'])->toBe($post->id);
    expect($row->status)->toBe('denied');
});

it('audit denials listener skips when feature flag is off', function () {
    config()->set('martis.audit.authz_denials', false);

    $user = AuthzTestUser::create(['email' => 'b@example.com']);
    $post = AuthzTestPost::create(['title' => 'admin', 'is_admin_only' => true]);

    (new RecordAuthorizationDenial)->handle(new \Illuminate\Auth\Access\Events\GateEvaluated(
        $user,
        'view-test-post',
        false,
        [$post],
    ));

    expect(ActionEvent::query()->count())->toBe(0);
});

it('audit denials listener skips allowed evaluations', function () {
    config()->set('martis.audit.authz_denials', true);

    $user = AuthzTestUser::create(['email' => 'c@example.com']);
    $post = AuthzTestPost::create(['title' => 'public']);

    (new RecordAuthorizationDenial)->handle(new \Illuminate\Auth\Access\Events\GateEvaluated(
        $user,
        'view-test-post',
        true,
        [$post],
    ));

    expect(ActionEvent::query()->where('name', 'authz.denied')->count())->toBe(0);
});

it('audit denials listener dedupes a repeat ability+model within one request', function () {
    config()->set('martis.audit.authz_denials', true);

    $user = AuthzTestUser::create(['email' => 'd@example.com']);
    $post = AuthzTestPost::create(['title' => 'admin', 'is_admin_only' => true]);

    $listener = new RecordAuthorizationDenial;
    $event = new \Illuminate\Auth\Access\Events\GateEvaluated($user, 'view-test-post', false, [$post]);

    $listener->handle($event);
    $listener->handle($event);
    $listener->handle($event);

    expect(ActionEvent::query()->where('name', 'authz.denied')->count())->toBe(1);
});

// -----------------------------------------------------------------------------
// C5 — Per-request ability cache
// -----------------------------------------------------------------------------

it('request-scoped ability cache memoises Gate decisions when feature flag is on', function () {
    config()->set('martis.authz.request_cache', true);

    $user = AuthzTestUser::create(['email' => 'e@example.com']);
    $post = AuthzTestPost::create(['title' => 'public']);

    /** @var RequestScopedAbilityCache $cache */
    $cache = app(RequestScopedAbilityCache::class);
    $cache->clear();

    // Drive the listener directly via a synthesised GateEvaluated
    // event. The framework dispatch path is exercised by integration
    // / browser tests; here we lock the listener's contract: when fed
    // a deterministic event with a User, it caches the result.
    $cache->handle(new \Illuminate\Auth\Access\Events\GateEvaluated(
        $user,
        'view-test-post',
        true,
        [$post],
    ));

    expect($cache->lookup($user->id, 'view-test-post', $post))->toBeTrue();
});

it('request-scoped ability cache short-circuits when feature flag is off', function () {
    config()->set('martis.authz.request_cache', false);

    $user = AuthzTestUser::create(['email' => 'f@example.com']);
    $post = AuthzTestPost::create(['title' => 'public']);

    $user->can('view-test-post', $post);

    /** @var RequestScopedAbilityCache $cache */
    $cache = app(RequestScopedAbilityCache::class);
    expect($cache->lookup($user->id, 'view-test-post', $post))->toBeNull();
});

// -----------------------------------------------------------------------------
// C4 — AssertsAuthorization trait
// -----------------------------------------------------------------------------

it('AssertsAuthorization::assertCan / assertCannot route through the gate', function () {
    $user = AuthzTestUser::create(['email' => 'g@example.com']);
    $public = AuthzTestPost::create(['title' => 'public']);
    $admin = AuthzTestPost::create(['title' => 'admin', 'is_admin_only' => true]);

    $this->assertCan($user, 'view-test-post', $public);
    $this->assertCannot($user, 'view-test-post', $admin);
});
