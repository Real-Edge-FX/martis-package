<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\File;
use Martis\Fields\Image;
use Martis\Profile\TwoFactorService;

/**
 * Regression guards for ecosystem-audit security highs:
 *  - TwoFactorService::generateSetup() must not overwrite a confirmed secret.
 *  - File::fillMultiple() must ignore client-supplied paths the model doesn't own.
 *  - Image::fillMultiple() must apply the same guard (it shipped without it).
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

it('Image::fillMultiple ignores client paths the model does not already own', function () {
    Storage::fake('local');

    $model = new AuditSecUser;
    $model->setAttribute('gallery', json_encode(['uploads/own-1.jpg']));

    $field = Image::make('gallery')->multiple()->disk('local');

    $field->fill($model, [
        'files' => [],
        'existing' => ['uploads/own-1.jpg', '../../secret.env', 'uploads/other-record.jpg'],
    ]);

    $stored = json_decode((string) $model->getAttribute('gallery'), true);

    expect($stored)->toBe(['uploads/own-1.jpg']);
});

it('Image::fillMultiple treats an owned path the client omits as a removal, thumbnail included', function () {
    Storage::fake('local');
    Storage::disk('local')->put('uploads/own-1.jpg', 'a');
    Storage::disk('local')->put('uploads/own-1_thumb.jpg', 'a');
    Storage::disk('local')->put('uploads/own-2.jpg', 'b');
    Storage::disk('local')->put('uploads/own-2_thumb.jpg', 'b');

    $model = new AuditSecUser;
    $model->setAttribute('gallery', json_encode(['uploads/own-1.jpg', 'uploads/own-2.jpg']));

    $field = Image::make('gallery')->multiple()->disk('local')->thumbnail(100, 100);

    // The client keeps own-2 only and tries to smuggle a foreign path in.
    $field->fill($model, [
        'files' => [],
        'existing' => ['uploads/other-record.jpg', 'uploads/own-2.jpg'],
    ]);

    expect(json_decode((string) $model->getAttribute('gallery'), true))->toBe(['uploads/own-2.jpg']);
    Storage::disk('local')->assertMissing('uploads/own-1.jpg');
    Storage::disk('local')->assertMissing('uploads/own-1_thumb.jpg');
    Storage::disk('local')->assertExists('uploads/own-2.jpg');
    Storage::disk('local')->assertExists('uploads/own-2_thumb.jpg');
});
