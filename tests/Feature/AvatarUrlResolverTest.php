<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Storage;
use Martis\Profile\AvatarService;
use Martis\Profile\ProfileResource;
use Martis\Tests\Fixtures\ConfigCallables\AvatarUrl;

beforeEach(function () {
    Storage::fake('public');
    config()->set('martis.profile.avatar.disk', 'public');
    config()->set('martis.profile.avatar.url_resolver', null);
});

dataset('url_resolver forms', [
    'invokable class name' => [AvatarUrl::class, 'https://cdn.example.test/invokable/avatars/a.png'],
    'static method array' => [[AvatarUrl::class, 'resolve'], 'https://cdn.example.test/static/avatars/a.png'],
    'closure' => [fn (string $path): string => 'https://cdn.example.test/closure/'.$path, 'https://cdn.example.test/closure/avatars/a.png'],
]);

function profileAvatarUrl(): ?string
{
    $user = (new User)->forceFill([
        'name' => 'Avatar User',
        'email' => 'avatar@example.test',
        'profile_picture' => 'avatars/a.png',
    ]);

    return (new ProfileResource)->toArray($user)['avatar_url'];
}

it('resolves the avatar URL through url_resolver in each callable form', function (mixed $resolver, string $expected) {
    config()->set('martis.profile.avatar.url_resolver', $resolver);

    expect(app(AvatarService::class)->resolveUrl('avatars/a.png', 'public'))->toBe($expected);
})->with('url_resolver forms');

it('serialises the profile avatar through url_resolver in each callable form', function (mixed $resolver, string $expected) {
    config()->set('martis.profile.avatar.url_resolver', $resolver);

    expect(profileAvatarUrl())->toBe($expected);
})->with('url_resolver forms');

it('falls back to the disk URL when url_resolver is unset', function () {
    $diskUrl = Storage::disk('public')->url('avatars/a.png');

    expect(app(AvatarService::class)->resolveUrl('avatars/a.png', 'public'))->toBe($diskUrl)
        ->and(profileAvatarUrl())->toBe($diskUrl);
});

it('rejects a url_resolver that is not a callable', function () {
    config()->set('martis.profile.avatar.url_resolver', AvatarUrl::class.'@handle');
    $message = 'The [martis.profile.avatar.url_resolver] config value is not a callable: no class or function is named "'.AvatarUrl::class.'@handle".';

    expect(fn () => app(AvatarService::class)->resolveUrl('avatars/a.png', 'public'))
        ->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => profileAvatarUrl())
        ->toThrow(InvalidArgumentException::class, $message);
});
