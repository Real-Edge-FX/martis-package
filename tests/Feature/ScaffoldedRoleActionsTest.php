<?php

declare(strict_types=1);

use App\Notifications\UserInvitation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\ActionFields;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Invitations\Invitation;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Stubs\StubResolver;

/**
 * The role actions `martis:invitations` and `martis:roles` scaffold, run for
 * real (F078, F106).
 *
 * Both Role pickers leave out the SSO-managed roles (a non-null
 * `provider_group_name`), but the actions took whatever role the request
 * named: `Select` never validated against its options. A forged `fields.role`
 * on InviteUser minted an invitation for any role, the roles above the
 * inviter's own included (a delegate of `martis-invite` could invite an admin
 * and accept it at a second address), and a forged `fields.role_id` on
 * BulkAssignRole assigned an SSO-managed role. The stubs are rendered into a
 * scratch namespace, loaded, and driven through the action endpoints.
 */
const GSR_NAMESPACE = 'Martis\\Tests\\GeneratedScaffolds';

class GsrUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> The role names this in-memory user holds (a stand-in for Spatie's HasRoles). */
    public array $heldRoles = [];

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->heldRoles, true);
    }

    /** @return Collection<int, string> */
    public function getRoleNames(): Collection
    {
        return collect($this->heldRoles);
    }

    public function assignRole(mixed $role): static
    {
        DB::table('gsr_user_roles')->insert(['user_id' => $this->getKey(), 'role_id' => $role->getKey()]);

        return $this;
    }
}

class GsrInvitationResource extends Resource
{
    public static function model(): string
    {
        return Invitation::class;
    }

    public static function uriKey(): string
    {
        return 'gsr-invitations';
    }

    public function fields(Request $request): array
    {
        return [Text::make('email')];
    }

    public function actions(Request $request): array
    {
        $invite = GSR_NAMESPACE.'\\InviteUser';

        return [(new $invite)->standalone()];
    }
}

class GsrUserResource extends Resource
{
    public static function model(): string
    {
        return GsrUser::class;
    }

    public static function uriKey(): string
    {
        return 'gsr-users';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function actions(Request $request): array
    {
        $assign = GSR_NAMESPACE.'\\BulkAssignRole';

        return [new $assign];
    }
}

/**
 * Render the scaffold stubs the way the commands do and load them once per
 * process: the generated classes are what an app runs.
 */
function gsrLoadScaffolds(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $directory = sys_get_temp_dir().'/martis-generated-scaffolds-'.getmypid();
    @mkdir($directory);
    register_shutdown_function(static function () use ($directory): void {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    });

    $stubs = [
        'InviteUser' => [__DIR__.'/../../stubs/invitations-invite-action.stub', ['{{ namespace }}' => GSR_NAMESPACE]],
        'BulkAssignRole' => [__DIR__.'/../../stubs/roles-bulk-assign-role-action.stub', ['{{ namespace }}' => GSR_NAMESPACE]],
    ];

    // The invite action sends the app's own notification; martis:invitations scaffolds it, no placeholders.
    if (! class_exists('App\\Notifications\\UserInvitation', false)) {
        $stubs['UserInvitation'] = [__DIR__.'/../../stubs/invitations-notification.stub', []];
    }

    foreach ($stubs as $class => [$stub, $replacements]) {
        $path = "{$directory}/{$class}.php";
        file_put_contents($path, strtr((string) file_get_contents($stub), $replacements));
        require_once $path;
    }

    $loaded = true;
}

/** Insert a role row; the SSO-managed ones carry the name of their IdP group. */
function gsrRole(string $name, ?string $providerGroup = null): int
{
    return (int) DB::table('roles')->insertGetId([
        'name' => $name,
        'guard_name' => 'web',
        'provider_group_name' => $providerGroup,
    ]);
}

/** A panel user holding the given roles, signed in on the default guard. */
function gsrSignIn(object $test, string $email, array $roles): GsrUser
{
    $user = GsrUser::query()->create(['name' => $email, 'email' => $email, 'password' => 'x']);
    $user->heldRoles = $roles;
    $test->actingAs($user);

    return $user;
}

beforeEach(function () {
    if (! class_exists('Spatie\\Permission\\Models\\Role')) {
        $this->markTestSkipped('spatie/laravel-permission not installed');
    }

    gsrLoadScaffolds();

    // Spatie's own defaults (table names, models): its provider is not booted here.
    $spatie = dirname((string) (new ReflectionClass('Spatie\\Permission\\Models\\Role'))->getFileName(), 3);
    config(['permission' => require $spatie.'/config/permission.php']);

    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['gsr_user_roles', 'invitations', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name')->default('web');
        $table->string('provider_group_name')->nullable();
        $table->timestamps();
    });
    Schema::create('gsr_user_roles', function ($table) {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
    });
    (require StubResolver::path('create_invitations_table.php.stub'))->up();

    config(['martis.invitations.enabled' => true]);
    Gate::define('martis-invite', fn ($user = null): bool => true);
    Gate::define('martis-invite-any-role', fn ($user = null): bool => false);

    Notification::fake();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(GsrInvitationResource::class);
    $registry->register(GsrUserResource::class);

    $this->localRoles = [
        'admin' => gsrRole('admin'),
        'editor' => gsrRole('editor'),
        'viewer' => gsrRole('viewer'),
    ];
    $this->ssoRole = gsrRole('azure-superusers', 'Azure Superusers');
});

afterEach(function () {
    foreach (['gsr_user_roles', 'invitations', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    app(ResourceRegistry::class)->flush();
});

/** POST the standalone InviteUser action with the given field values. */
function gsrInvite(object $test, array $fields): TestResponse
{
    return $test->postJson('/martis/api/resources/gsr-invitations/actions/invite-user', ['resources' => [], 'fields' => $fields]);
}

/** The values the InviteUser Role picker lists. */
function gsrInviteOptions(object $test): array
{
    $fields = $test->getJson('/martis/api/resources/gsr-invitations/actions/invite-user/fields')->assertOk()->json('data.fields');

    return array_column(array_column($fields, null, 'attribute')['role']['options'], 'value');
}

// ---------------------------------------------------------------------------
// InviteUser (F078)
// ---------------------------------------------------------------------------

it('invites with a role the picker offers, and sends the notification', function () {
    gsrSignIn($this, 'admin@example.com', ['admin']);

    gsrInvite($this, ['email' => 'new@example.com', 'role' => 'editor'])->assertOk()->assertJsonPath('data.type', 'message');

    expect(Invitation::query()->sole()->only(['email', 'role']))->toBe(['email' => 'new@example.com', 'role' => 'editor']);
    Notification::assertSentOnDemandTimes(UserInvitation::class, 1);
});

it('invites without a role', function () {
    gsrSignIn($this, 'admin@example.com', ['admin']);

    gsrInvite($this, ['email' => 'norole@example.com'])->assertOk();
    gsrInvite($this, ['email' => 'empty@example.com', 'role' => ''])->assertOk();

    expect(Invitation::query()->pluck('role', 'email')->all())->toBe(['norole@example.com' => null, 'empty@example.com' => null]);
});

it('refuses a role the picker does not offer: an SSO-managed or unknown role mints no invitation', function (string $role) {
    gsrSignIn($this, 'admin@example.com', ['admin']);

    gsrInvite($this, ['email' => 'forged@example.com', 'role' => $role])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'role');

    expect(Invitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'an SSO-managed role' => ['azure-superusers'],
    'a role that does not exist' => ['root'],
    'a role under another case' => ['Admin'],
]);

it('lists the local roles in the picker, never an SSO-managed one', function () {
    gsrSignIn($this, 'admin@example.com', ['admin']);

    expect(gsrInviteOptions($this))->toBe(['admin', 'editor', 'viewer']);
});

it('offers a delegated inviter the roles they hold and refuses the rest', function () {
    gsrSignIn($this, 'delegate@example.com', ['editor']);

    expect(gsrInviteOptions($this))->toBe(['editor']);

    // The roles above their own, the one the finding named: a delegate of
    // `martis-invite` inviting an admin and accepting it at a second address.
    gsrInvite($this, ['email' => 'escalate@example.com', 'role' => 'admin'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'role');
    gsrInvite($this, ['email' => 'sideways@example.com', 'role' => 'viewer'])->assertStatus(422);

    expect(Invitation::query()->count())->toBe(0);
    Notification::assertNothingSent();

    gsrInvite($this, ['email' => 'peer@example.com', 'role' => 'editor'])->assertOk();

    expect(Invitation::query()->sole()->role)->toBe('editor');
});

it('never lets a delegated inviter hand out an SSO-managed role they hold', function () {
    gsrSignIn($this, 'sso@example.com', ['azure-superusers']);

    expect(gsrInviteOptions($this))->toBe([]);

    gsrInvite($this, ['email' => 'forged@example.com', 'role' => 'azure-superusers'])->assertStatus(422);

    expect(Invitation::query()->count())->toBe(0);
});

it('lets an inviter who passes martis-invite-any-role hand out every local role', function () {
    Gate::define('martis-invite-any-role', fn ($user = null): bool => true);
    gsrSignIn($this, 'lead@example.com', ['editor']);

    expect(gsrInviteOptions($this))->toBe(['admin', 'editor', 'viewer']);

    gsrInvite($this, ['email' => 'viewer@example.com', 'role' => 'viewer'])->assertOk();
    gsrInvite($this, ['email' => 'sso@example.com', 'role' => 'azure-superusers'])->assertStatus(422);

    expect(Invitation::query()->pluck('role', 'email')->all())->toBe(['viewer@example.com' => 'viewer']);
});

it('lets a delegate with no role invite nobody into a role, and still invite without one', function () {
    gsrSignIn($this, 'norole@example.com', []);

    expect(gsrInviteOptions($this))->toBe([]);

    gsrInvite($this, ['email' => 'x@example.com', 'role' => 'editor'])->assertStatus(422);
    gsrInvite($this, ['email' => 'y@example.com'])->assertOk();

    expect(Invitation::query()->pluck('role', 'email')->all())->toBe(['y@example.com' => null]);
});

it('checks the role again in handle(), whatever reached it', function () {
    // The validation above runs in the action endpoint. handle() is also the
    // entry of a custom controller, a test or a queued wrapper: it must not
    // trust the value either.
    gsrSignIn($this, 'delegate@example.com', ['editor']);
    $class = GSR_NAMESPACE.'\\InviteUser';

    $refused = (new $class)->handle(new ActionFields(['email' => 'escalate@example.com', 'role' => 'admin']), collect());

    expect($refused->jsonSerialize()['type'])->toBe('danger');
    expect(Invitation::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $sso = (new $class)->handle(new ActionFields(['email' => 'sso@example.com', 'role' => 'azure-superusers']), collect());

    expect($sso->jsonSerialize()['type'])->toBe('danger');
    expect(Invitation::query()->count())->toBe(0);

    $allowed = (new $class)->handle(new ActionFields(['email' => 'peer@example.com', 'role' => 'editor']), collect());

    expect($allowed->jsonSerialize()['type'])->toBe('message');
    expect(Invitation::query()->sole()->role)->toBe('editor');
});

it('works on a roles table without the SSO column, as before', function () {
    Schema::table('roles', fn ($table) => $table->dropColumn('provider_group_name'));
    gsrSignIn($this, 'admin@example.com', ['admin']);

    expect(gsrInviteOptions($this))->toBe(['admin', 'azure-superusers', 'editor', 'viewer']);

    gsrInvite($this, ['email' => 'new@example.com', 'role' => 'editor'])->assertOk();
    gsrInvite($this, ['email' => 'forged@example.com', 'role' => 'root'])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// BulkAssignRole (F106)
// ---------------------------------------------------------------------------

/** POST the BulkAssignRole action on the given users. */
function gsrAssign(object $test, array $userIds, mixed $roleId): TestResponse
{
    return $test->postJson('/martis/api/resources/gsr-users/actions/bulk-assign-role', ['resources' => $userIds, 'fields' => ['role_id' => $roleId]]);
}

/** @return list<int> The users that hold the role, by id. */
function gsrHolders(int $roleId): array
{
    return DB::table('gsr_user_roles')->where('role_id', $roleId)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
}

it('assigns a local role to the selected users', function () {
    $operator = gsrSignIn($this, 'admin@example.com', ['admin']);
    $other = GsrUser::query()->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'x']);

    gsrAssign($this, [$operator->id, $other->id], $this->localRoles['editor'])->assertOk()->assertJsonPath('data.type', 'message');

    expect(gsrHolders($this->localRoles['editor']))->toBe([$operator->id, $other->id]);
});

it('refuses an SSO-managed or missing role id and assigns nothing', function (string $which) {
    $operator = gsrSignIn($this, 'admin@example.com', ['admin']);
    $roleId = $which === 'sso' ? $this->ssoRole : 9999;

    gsrAssign($this, [$operator->id], $roleId)
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'role_id');

    expect(DB::table('gsr_user_roles')->count())->toBe(0);
})->with([
    'an SSO-managed role' => ['sso'],
    'a role id that does not exist' => ['missing'],
]);

it('refuses a role id sent as the SSO role name or in another shape', function (mixed $roleId) {
    $operator = gsrSignIn($this, 'admin@example.com', ['admin']);

    gsrAssign($this, [$operator->id], $roleId)->assertStatus(422);

    expect(DB::table('gsr_user_roles')->count())->toBe(0);
})->with([
    'a role name' => ['editor'],
    'an array' => [[1]],
    'a boolean' => [true],
]);

it('lists the local roles in the BulkAssignRole picker, never an SSO-managed one', function () {
    gsrSignIn($this, 'admin@example.com', ['admin']);

    $fields = $this->getJson('/martis/api/resources/gsr-users/actions/bulk-assign-role/fields')->assertOk()->json('data.fields');
    $options = array_column($fields, null, 'attribute')['role_id']['options'];

    expect(array_column($options, 'label'))->toBe(['admin', 'editor', 'viewer'])
        ->and(array_column($options, 'value'))->toBe([$this->localRoles['admin'], $this->localRoles['editor'], $this->localRoles['viewer']]);
});

it('looks the role up through the picker query in handle(), whatever reached it', function () {
    $operator = gsrSignIn($this, 'admin@example.com', ['admin']);
    $class = GSR_NAMESPACE.'\\BulkAssignRole';
    $models = collect([$operator]);

    $sso = (new $class)->handle(new ActionFields(['role_id' => $this->ssoRole]), $models);

    expect($sso->jsonSerialize()['type'])->toBe('danger');
    expect(DB::table('gsr_user_roles')->count())->toBe(0);

    $local = (new $class)->handle(new ActionFields(['role_id' => $this->localRoles['viewer']]), $models);

    expect($local->jsonSerialize()['type'])->toBe('message');
    expect(gsrHolders($this->localRoles['viewer']))->toBe([$operator->id]);
});

it('works on a roles table without the SSO column, as before (BulkAssignRole)', function () {
    Schema::table('roles', fn ($table) => $table->dropColumn('provider_group_name'));
    $operator = gsrSignIn($this, 'admin@example.com', ['admin']);

    gsrAssign($this, [$operator->id], $this->ssoRole)->assertOk();
    gsrAssign($this, [$operator->id], 9999)->assertStatus(422);

    expect(gsrHolders($this->ssoRole))->toBe([$operator->id]);
});
