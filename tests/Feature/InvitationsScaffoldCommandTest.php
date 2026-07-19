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

    // Resource shape: System section, config-gated visibility, wires the actions.
    $resource = (string) file_get_contents(app_path('Martis/Resources/InvitationResource.php'));
    expect($resource)->toContain('belongsToSystemSection')
        ->toContain('return true')
        ->toContain("config('martis.invitations.enabled')")
        ->toContain('Martis\\Invitations\\Invitation')
        ->toContain('InviteUser')
        ->toContain('ResendInvitation')
        ->toContain('RevokeInvitation');

    // Invite action: manager call + notification + gate.
    $invite = (string) file_get_contents(app_path('Martis/Resources/Actions/InviteUser.php'));
    expect($invite)->toContain('InvitationManager')
        ->toContain('->invite(')
        ->toContain('UserInvitation')
        ->toContain('martis-invite');

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
