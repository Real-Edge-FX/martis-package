<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Auth\Listeners\RecordImpersonation;
use Martis\Fields\Text;
use Martis\Impersonation\Events\ImpersonationStarted;
use Martis\Invitations\InvitationManager;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Stubs\StubResolver;
use Martis\Tests\Fixtures\GuardUsers\Admin;
use Martis\Tests\Fixtures\GuardUsers\SiteUser;

/*
 * A panel request with a custom MARTIS_GUARD reads "the current user" as the
 * Martis guard's user wherever the panel reads it: MartisAuthenticate makes
 * that guard the request's guard, so `$request->user()`, `auth()->user()`,
 * the `current_password` rule and the gates see it. The admin and the site
 * user are signed in the same browser through a real session (actingAs()
 * calls shouldUse() itself and would hide the reads that miss it), with
 * different ids, so a read of the wrong guard shows. The writers of the
 * audit log, whose user() resolves the Martis guard's model, record no user
 * of another guard.
 */

class PanelGuardItem extends Model
{
    protected $table = 'martis_test_panel_items';

    protected $guarded = [];
}

class PanelGuardItemPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    public function update($user, $model): bool
    {
        return true;
    }

    public function runAction($user): bool
    {
        return true;
    }
}

class PanelGuardTouch extends Action
{
    public ?string $name = 'Touch';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Touched.');
    }
}

class PanelGuardItemResource extends Resource
{
    public static ?string $policy = PanelGuardItemPolicy::class;

    public static function model(): string
    {
        return PanelGuardItem::class;
    }

    public static function uriKey(): string
    {
        return 'panel-guard-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [PanelGuardTouch::make()];
    }
}

const PANEL_GUARD_TABLES = ['martis_test_guard_users_admins', 'users', 'martis_test_panel_items', 'martis_action_events', 'invitations'];

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'guard_users_admins']);
    config()->set('auth.providers.guard_users_admins', ['driver' => 'eloquent', 'model' => Admin::class]);
    config()->set('auth.providers.users.model', SiteUser::class);
    config()->set('martis.guard', 'admin');

    foreach (PANEL_GUARD_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    foreach (['martis_test_guard_users_admins', 'users'] as $table) {
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
    Schema::create('martis_test_panel_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->uuid('batch_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->json('fields')->nullable();
        $t->string('status');
        $t->text('exception')->nullable();
        $t->json('original')->nullable();
        $t->json('changes')->nullable();
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PanelGuardItemResource::class);

    // The site user takes id 1, the admin id 2: a read of the wrong guard
    // names a different id.
    $this->site = SiteUser::create(['name' => 'Site', 'email' => 'site@example.com', 'password' => Hash::make('site-secret'), 'email_verified_at' => now()]);
    $this->other = Admin::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('other-secret'), 'email_verified_at' => now()]);
    $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('admin-secret'), 'email_verified_at' => now()]);
    expect([$this->site->getKey(), $this->admin->getKey()])->toBe([1, 2]);
});

afterEach(function () {
    app(ResourceRegistry::class)->flush();
    foreach (PANEL_GUARD_TABLES as $table) {
        Schema::dropIfExists($table);
    }
});

/** The session of a browser signed in to the site as the site user and to the panel as this admin. */
function panelGuardBoth(Admin $admin): array
{
    return [
        auth()->guard('web')->getName() => test()->site->getKey(),
        auth()->guard('admin')->getName() => $admin->getKey(),
    ];
}

/** Start the next request as a new process would: no guard keeps the user it loaded, the default guard is the app's. */
function panelGuardNextRequest(): void
{
    test()->flushSession();
    auth()->forgetGuards();
    config()->set('auth.defaults.guard', 'web');
}

it('applies the email verification gate to the Martis guard user', function () {
    config()->set('martis.auth.email_verification.enabled', true);

    $this->site->forceFill(['email_verified_at' => null])->save();
    $this->withSession(panelGuardBoth($this->admin))
        ->getJson('/martis/api/resources/panel-guard-items')
        ->assertOk();

    panelGuardNextRequest();
    $this->site->forceFill(['email_verified_at' => now()])->save();
    $this->admin->forceFill(['email_verified_at' => null])->save();
    $this->withSession(panelGuardBoth($this->admin))
        ->getJson('/martis/api/resources/panel-guard-items')
        ->assertStatus(409);
});

it('records the Martis guard user as the actor of an action', function () {
    $item = PanelGuardItem::create(['title' => 'One']);

    $this->withSession(panelGuardBoth($this->admin))
        ->postJson('/martis/api/resources/panel-guard-items/actions/panel-guard-touch', ['resources' => [$item->getKey()]])
        ->assertOk();

    $event = ActionEvent::query()->where('name', 'Touch')->sole();
    expect($event->user_id)->toBe($this->admin->getKey())
        ->and($event->user)->toBeInstanceOf(Admin::class)
        ->and($event->user?->getKey())->toBe($this->admin->getKey());
});

it('checks the current password of the Martis guard user', function () {
    $change = fn (string $current) => $this->withSession(panelGuardBoth($this->admin))->postJson('/martis/api/profile/password', [
        'current_password' => $current,
        'password' => 'New-secret-123',
        'password_confirmation' => 'New-secret-123',
    ]);

    $change('site-secret')->assertStatus(422)->assertJsonValidationErrors('current_password');
    panelGuardNextRequest();
    $change('admin-secret')->assertOk();

    expect(Hash::check('New-secret-123', (string) $this->admin->fresh()?->getAuthPassword()))->toBeTrue()
        ->and(Hash::check('site-secret', (string) $this->site->fresh()?->getAuthPassword()))->toBeTrue();
});

it('checks the profile email among the Martis guard users', function () {
    $update = fn (string $email) => $this->withSession(panelGuardBoth($this->admin))->patchJson('/martis/api/profile', ['name' => 'Admin', 'email' => $email]);

    // Another admin's email is taken; the site account's is not a conflict.
    $update('other@example.com')->assertStatus(422)->assertJsonValidationErrors('email');
    panelGuardNextRequest();
    $update('site@example.com')->assertOk();
    // Their own email, unchanged, validates.
    panelGuardNextRequest();
    $update('site@example.com')->assertOk();

    expect($this->admin->fresh()?->email)->toBe('site@example.com');
});

it('records an authorization denial only for the Martis guard user', function () {
    config()->set('martis.audit.authz_denials', true);
    Gate::define('panel-guard-probe', fn ($user): bool => false);

    // A site request: the site guard is the request's guard.
    auth()->shouldUse('web');
    auth()->guard('web')->setUser($this->site);
    expect(Gate::allows('panel-guard-probe'))->toBeFalse();
    expect(ActionEvent::query()->where('name', 'authz.denied')->count())->toBe(0);

    // A panel request.
    auth()->shouldUse('admin');
    auth()->guard('admin')->setUser($this->admin);
    expect(Gate::allows('panel-guard-probe'))->toBeFalse();
    expect(ActionEvent::query()->where('name', 'authz.denied')->pluck('user_id')->all())->toBe([$this->admin->getKey()]);
});

it('records the inviter, not a site user, as the actor of an invitation revoked outside the panel', function () {
    config()->set('martis.invitations.enabled', true);
    (require StubResolver::path('create_invitations_table.php.stub'))->up();

    auth()->shouldUse('admin');
    auth()->guard('admin')->setUser($this->admin);
    $invitation = app(InvitationManager::class)->invite('ann@example.com');
    expect($invitation->invited_by)->toBe($this->admin->getKey());

    auth()->shouldUse('web');
    auth()->guard('web')->setUser($this->site);
    app(InvitationManager::class)->revoke($invitation);

    expect(ActionEvent::query()->where('name', 'invitation.revoked')->sole()->user_id)->toBe($this->admin->getKey());
});

it('records the operator of an impersonation as the actor only when the Martis guard signs them in', function () {
    config()->set('martis.impersonation.guard', 'web');
    $target = SiteUser::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => Hash::make('x')]);

    app(RecordImpersonation::class)->handleStarted(new ImpersonationStarted($this->site, $target));

    $event = ActionEvent::query()->where('name', 'impersonation.started')->sole();
    expect($event->user_id)->toBeNull()
        ->and($event->fields['operator_type'] ?? null)->toBe(SiteUser::class)
        ->and($event->fields['operator_id'] ?? null)->toBe($this->site->getKey());

    config()->set('martis.impersonation.guard', null);
    app(RecordImpersonation::class)->handleStarted(new ImpersonationStarted($this->admin, $this->other));

    $event = ActionEvent::query()->where('name', 'impersonation.started')->latest('id')->first();
    expect($event?->user_id)->toBe($this->admin->getKey())
        ->and($event?->fields)->not->toHaveKey('operator_type');
});

it('creates the admin of martis:user among the Martis guard users', function () {
    $this->artisan('martis:user', ['--name' => 'Cli', '--email' => 'cli@example.com', '--password' => 'secret123'])
        ->assertSuccessful();

    expect(Admin::query()->where('email', 'cli@example.com')->first()?->email_verified_at)->not->toBeNull()
        ->and(SiteUser::query()->where('email', 'cli@example.com')->exists())->toBeFalse();

    // A Martis guard table without a verification column.
    Schema::table('martis_test_guard_users_admins', fn ($table) => $table->dropColumn('email_verified_at'));
    $this->artisan('martis:user', ['--name' => 'Bare', '--email' => 'bare@example.com', '--password' => 'secret123'])
        ->assertSuccessful();

    expect(Admin::query()->where('email', 'bare@example.com')->exists())->toBeTrue();
});
