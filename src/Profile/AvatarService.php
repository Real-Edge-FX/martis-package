<?php

namespace Martis\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Handles avatar upload, removal, and URL resolution for the profile page.
 */
class AvatarService
{
    /**
     * Upload a new avatar for the given user.
     *
     * Validates mime type and file size, stores the file on the configured
     * disk, updates the user model, and returns the public URL.
     *
     * @return string Public URL of the stored avatar.
     */
    public function upload(Authenticatable $user, UploadedFile $file): string
    {
        $disk = (string) config('martis.profile.avatar.disk', config('martis.storage.disk', 'public'));
        $path = (string) config('martis.profile.avatar.path', 'avatars');
        $column = (string) config('martis.profile.avatar.column', 'profile_picture');

        /** @var Model&Authenticatable $user */
        if ($user->{$column}) {
            Storage::disk($disk)->delete((string) $user->{$column});
        }

        $stored = $file->store($path, $disk);

        if ($stored === false) {
            throw new \RuntimeException('Failed to store avatar file.');
        }

        $user->{$column} = $stored;
        $user->save();

        return $this->resolveUrl($stored, $disk);
    }

    /**
     * Remove the user's avatar and clear the column.
     */
    public function remove(Authenticatable $user): void
    {
        $disk = (string) config('martis.profile.avatar.disk', config('martis.storage.disk', 'public'));
        $column = (string) config('martis.profile.avatar.column', 'profile_picture');

        /** @var Model&Authenticatable $user */
        if ($user->{$column}) {
            Storage::disk($disk)->delete((string) $user->{$column});
        }

        $user->{$column} = null;
        $user->save();
    }

    /**
     * Resolve the public URL for a stored avatar path.
     */
    public function resolveUrl(string $storedPath, string $disk): string
    {
        $resolver = config('martis.profile.avatar.url_resolver');
        if (is_callable($resolver)) {
            return (string) $resolver($storedPath);
        }

        return Storage::disk($disk)->url($storedPath); // @phpstan-ignore-line method.notFound
    }
}
