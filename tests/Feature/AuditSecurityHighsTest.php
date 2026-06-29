<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\File;
use Martis\Profile\TwoFactorService;

/**
 * Regression guards for ecosystem-audit security highs:
 *  - TwoFactorService::generateSetup() must not overwrite a confirmed secret.
 *  - File::fillMultiple() must ignore client-supplied paths the model doesn't own.
 *
 * Feature-level (full app) because generateSetup() needs an Authenticatable
 * and the File field resolves storage paths through the container.
 */
class AuditSecUser extends Authenticatable
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'audit_sec_users';
}

it('TwoFactorService::generateSetup refuses to overwrite an already-confirmed secret', function () {
    $service = new TwoFactorService;
    $user = new AuditSecUser;
    $user->setAttribute('two_factor_confirmed_at', now()); // 2FA already enabled

    // The guard runs before any secret generation or save(), so no DB is hit.
    expect(fn () => $service->generateSetup($user))
        ->toThrow(InvalidArgumentException::class);
});

it('File::fillMultiple ignores client paths the model does not already own', function () {
    Storage::fake('local');

    $model = new AuditSecUser;
    // The model legitimately owns one file.
    $model->setAttribute('attachments', json_encode(['uploads/own-1.pdf']));

    $field = File::make('attachments')->multiple()->disk('local');

    // Client posts an injected path (another record's file / a traversal)
    // alongside the owned one. Only the owned path may survive.
    $field->fill($model, [
        'files' => [],
        'existing' => ['uploads/own-1.pdf', '../../secret.env', 'uploads/other-record.pdf'],
    ]);

    $stored = json_decode((string) $model->getAttribute('attachments'), true);

    expect($stored)->toBe(['uploads/own-1.pdf']);
});
