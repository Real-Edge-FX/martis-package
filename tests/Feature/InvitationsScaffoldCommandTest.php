<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

/*
 * `martis:invitations` — pin the contract that the command:
 *
 *   1. Scaffolds the six CONSUMER-OWNED files (InvitationResource, the
 *      InviteUser / ResendInvitation / RevokeInvitation actions, the
 *      InvitationPolicy, and the UserInvitation notification) into the
 *      host app under the configured namespace.
 *   2. Publishes the `create_invitations_table` migration (family-glob
 *      skip so re-runs never double-publish).
 *   3. Is idempotent — re-running without `--force` leaves existing
 *      files untouched; `--force` overwrites.
 *   4. Never leaks the word "tenant" into any generated artefact.
 *
 * The backend primitive (InvitationManager, events, routes, accept
 * screen) is exercised elsewhere; this test only covers the generator.
 */

/**
 * @return list<string>
 */
function invitationsMigrationFiles(): array
{
    return (array) glob(base_path('database/migrations/*_create_invitations_table.php'));
}

beforeEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);

    foreach ([
        app_path('Martis/Resources'),
        app_path('Policies'),
        app_path('Notifications'),
        app_path('Providers'),
    ] as $dir) {
        if ($files->isDirectory($dir)) {
            $files->deleteDirectory($dir);
        }
    }

    foreach (invitationsMigrationFiles() as $file) {
        @unlink((string) $file);
    }

    // Give the command an AuthServiceProvider to patch so the
    // policy-registration path is exercised instead of the manual note.
    $authProvider = app_path('Providers/AuthServiceProvider.php');
    $files->ensureDirectoryExists(dirname($authProvider));
    $files->put($authProvider, <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}

PHP);
});

afterEach(function () {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);

    foreach ([
        app_path('Martis/Resources'),
        app_path('Policies'),
        app_path('Notifications'),
        app_path('Providers'),
    ] as $dir) {
        if ($files->isDirectory($dir)) {
            $files->deleteDirectory($dir);
        }
    }

    foreach (invitationsMigrationFiles() as $file) {
        @unlink((string) $file);
    }
});

it('martis:invitations is registered with the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:invitations');
});

it('scaffolds the six consumer-owned files and publishes the migration', function () {
    $exitCode = $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    expect($exitCode)->toBe(0);

    $targets = [
        app_path('Martis/Resources/InvitationResource.php'),
        app_path('Martis/Resources/Actions/InviteUser.php'),
        app_path('Martis/Resources/Actions/ResendInvitation.php'),
        app_path('Martis/Resources/Actions/RevokeInvitation.php'),
        app_path('Policies/InvitationPolicy.php'),
        app_path('Notifications/UserInvitation.php'),
    ];

    foreach ($targets as $path) {
        expect(file_exists($path))->toBeTrue("missing {$path}");
    }

    // The migration is published exactly once.
    expect(invitationsMigrationFiles())->toHaveCount(1);

    // Resource shape: System section, config-gated routing + visibility, wires the actions.
    $resource = (string) file_get_contents(app_path('Martis/Resources/InvitationResource.php'));
    expect($resource)->toContain('belongsToSystemSection')
        ->toContain('return true')
        ->toContain('public static function routable()')
        ->toContain("config('martis.invitations.enabled')")
        ->toContain('Martis\\Invitations\\Invitation')
        ->toContain('InviteUser')
        ->toContain('ResendInvitation')
        ->toContain('RevokeInvitation');

    // Invite action: manager call + notification + gate, and the action is
    // itself gated on the enabled flag (default-off issuing side).
    $invite = (string) file_get_contents(app_path('Martis/Resources/Actions/InviteUser.php'));
    expect($invite)->toContain('InvitationManager')
        ->toContain('->invite(')
        ->toContain('UserInvitation')
        ->toContain('martis-invite')
        ->toContain("config('martis.invitations.enabled', false)");

    // Policy: the generic create form is closed — invitations are issued
    // via the InviteUser action, never a form that lacks the token field.
    $policyCreate = (string) file_get_contents(app_path('Policies/InvitationPolicy.php'));
    expect($policyCreate)->toMatch('/function create\([^)]*\)\s*:\s*bool\s*\{\s*return false;/');

    $resend = (string) file_get_contents(app_path('Martis/Resources/Actions/ResendInvitation.php'));
    expect($resend)->toContain('->resend(')
        ->toContain('UserInvitation');

    $revoke = (string) file_get_contents(app_path('Martis/Resources/Actions/RevokeInvitation.php'));
    expect($revoke)->toContain('->revoke(');

    // Policy: admin-only default.
    $policy = (string) file_get_contents(app_path('Policies/InvitationPolicy.php'));
    expect($policy)->toContain("hasRole('admin')");

    // Notification: mailable with the accept URL.
    $notification = (string) file_get_contents(app_path('Notifications/UserInvitation.php'));
    expect($notification)->toContain('MailMessage')
        ->toContain('namespace App\\Notifications');

    // Policy is wired into AuthServiceProvider so it actually governs the resource.
    $authProvider = (string) file_get_contents(app_path('Providers/AuthServiceProvider.php'));
    expect($authProvider)->toContain('InvitationPolicy::class')
        ->toContain('martis:invitations policy');
});

it('scaffolds a default-off resource whose routable() follows config(martis.invitations.enabled)', function () {
    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    $resourcePath = app_path('Martis/Resources/InvitationResource.php');
    expect(file_exists($resourcePath))->toBeTrue("missing {$resourcePath}");

    // Load the generated class and exercise the *real* static method,
    // rather than string-matching the file. `routable()` reads config at
    // call time, so toggling the flag must flip the return value.
    $fqcn = 'App\\Martis\\Resources\\InvitationResource';

    if (! class_exists($fqcn, false)) {
        require $resourcePath;
    }

    // Default (feature off): the resource is NOT routable — its
    // index/detail/create endpoints 404 and it never reaches the command
    // palette or global search. This is the issuing-side mirror of the
    // 503 the accept side returns.
    config(['martis.invitations.enabled' => false]);
    expect($fqcn::routable())->toBeFalse('routable() must be false when invitations are disabled');
    expect($fqcn::displayInNavigation())->toBeFalse('sidebar must stay hidden when invitations are disabled');

    // Flip the flag: the whole surface comes alive.
    config(['martis.invitations.enabled' => true]);
    expect($fqcn::routable())->toBeTrue('routable() must be true when invitations are enabled');
    expect($fqcn::displayInNavigation())->toBeTrue('sidebar must appear when invitations are enabled');
});

it('emits zero "tenant" language across the generated files', function () {
    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    $files = [
        app_path('Martis/Resources/InvitationResource.php'),
        app_path('Martis/Resources/Actions/InviteUser.php'),
        app_path('Martis/Resources/Actions/ResendInvitation.php'),
        app_path('Martis/Resources/Actions/RevokeInvitation.php'),
        app_path('Policies/InvitationPolicy.php'),
        app_path('Notifications/UserInvitation.php'),
    ];

    foreach ($files as $path) {
        $body = (string) file_get_contents($path);
        expect(stripos($body, 'tenant'))->toBeFalse("{$path} mentions \"tenant\"");
    }
});

it('is idempotent — re-running without --force skips existing files and does not re-publish the migration', function () {
    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    $resourcePath = app_path('Martis/Resources/InvitationResource.php');
    $pristineHash = md5((string) file_get_contents($resourcePath));

    // Mutate to detect an unwanted overwrite.
    file_put_contents($resourcePath, "/* user-customised */\n".file_get_contents($resourcePath));
    $mutatedHash = md5((string) file_get_contents($resourcePath));

    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    $afterHash = md5((string) file_get_contents($resourcePath));

    expect($afterHash)->toBe($mutatedHash)
        ->and($afterHash)->not->toBe($pristineHash)
        // Family-glob skip: still exactly one migration on disk.
        ->and(invitationsMigrationFiles())->toHaveCount(1);
});

it('--force overwrites existing scaffold files', function () {
    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
    ])->run();

    $resourcePath = app_path('Martis/Resources/InvitationResource.php');
    $pristineHash = md5((string) file_get_contents($resourcePath));

    file_put_contents($resourcePath, "/* user-customised */\n".file_get_contents($resourcePath));

    $this->artisan('martis:invitations', [
        '--namespace' => 'App\\Martis\\Resources',
        '--no-install' => true,
        '--no-migrate' => true,
        '--force' => true,
    ])->run();

    $afterHash = md5((string) file_get_contents($resourcePath));

    expect($afterHash)->toBe($pristineHash);
});
