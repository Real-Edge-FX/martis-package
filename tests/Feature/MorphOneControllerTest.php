<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — User and Team each have one morph-one Avatar.
// ---------------------------------------------------------------------------

class MOUserModel extends Model
{
    protected $table = 'mo_test_users';

    protected $fillable = ['name'];

    public function avatar(): EloquentMorphOne
    {
        return $this->morphOne(MOAvatarModel::class, 'imageable');
    }
}

class MOTeamModel extends Model
{
    protected $table = 'mo_test_teams';

    protected $fillable = ['name'];

    public function avatar(): EloquentMorphOne
    {
        return $this->morphOne(MOAvatarModel::class, 'imageable');
    }
}

class MOAvatarModel extends Model
{
    protected $table = 'mo_test_avatars';

    protected $fillable = ['url', 'imageable_type', 'imageable_id'];
}

class MOUserResource extends Resource
{
    public static function model(): string
    {
        return MOUserModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            MorphOne::make('Avatar', 'avatar')->relatedResource('m-o-avatar-models'),
        ];
    }
}

class MOTeamResource extends Resource
{
    public static function model(): string
    {
        return MOTeamModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            MorphOne::make('Avatar', 'avatar')->relatedResource('m-o-avatar-models'),
        ];
    }
}

class MOAvatarResource extends Resource
{
    public static function model(): string
    {
        return MOAvatarModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('url')->required(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mo_test_avatars');
    Schema::dropIfExists('mo_test_users');
    Schema::dropIfExists('mo_test_teams');

    Schema::create('mo_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('mo_test_teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('mo_test_avatars', function ($table) {
        $table->id();
        $table->string('url');
        $table->morphs('imageable');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MOUserResource::class);
    $registry->register(MOTeamResource::class);
    $registry->register(MOAvatarResource::class);
});

afterEach(function () {
    Schema::dropIfExists('mo_test_avatars');
    Schema::dropIfExists('mo_test_users');
    Schema::dropIfExists('mo_test_teams');
});

// ---------------------------------------------------------------------------
// Show — returns the related model
// ---------------------------------------------------------------------------

it('returns the related morph-one model when present', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    MOAvatarModel::create([
        'url' => 'https://cdn.example/alice.png',
        'imageable_type' => MOUserModel::class,
        'imageable_id' => $user->id,
    ]);

    $response = $this->getJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar");

    $response->assertOk();
    expect($response->json('data.url'))->toBe('https://cdn.example/alice.png');
});

it('returns null when the morph-one related model does not exist', function () {
    $user = MOUserModel::create(['name' => 'Empty']);

    $response = $this->getJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar");

    $response->assertOk();
    expect($response->json('data'))->toBeNull();
});

it('does not leak morph-one model from another type with the same ID', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    $team = MOTeamModel::create(['name' => 'Engineers']);
    MOAvatarModel::create([
        'url' => 'https://cdn.example/team.png',
        'imageable_type' => MOTeamModel::class,
        'imageable_id' => $team->id,
    ]);

    // User has no avatar even though Team has one with the same ID.
    $response = $this->getJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar");
    expect($response->json('data'))->toBeNull();

    $teamResp = $this->getJson("/martis/api/resources/m-o-team-models/{$team->id}/morph-one/avatar");
    expect($teamResp->json('data.url'))->toBe('https://cdn.example/team.png');
});

// ---------------------------------------------------------------------------
// Store — creates a fresh morph-one
// ---------------------------------------------------------------------------

it('stores the related morph-one model with the correct morph keys', function () {
    $user = MOUserModel::create(['name' => 'Bob']);

    $response = $this->postJson(
        "/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar",
        ['url' => 'https://cdn.example/bob.png'],
    );

    $response->assertStatus(201);

    $avatar = MOAvatarModel::where('url', 'https://cdn.example/bob.png')->first();
    expect($avatar)->not->toBeNull();
    expect($avatar->imageable_type)->toBe(MOUserModel::class);
    expect((int) $avatar->imageable_id)->toBe($user->id);
});

it('store validates required fields on morph-one', function () {
    $user = MOUserModel::create(['name' => 'Bob']);

    $response = $this->postJson(
        "/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar",
        [],
    );

    $response->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Update — operates on the existing morph-one
// ---------------------------------------------------------------------------

it('updates the existing morph-one model', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    MOAvatarModel::create([
        'url' => 'https://cdn.example/old.png',
        'imageable_type' => MOUserModel::class,
        'imageable_id' => $user->id,
    ]);

    $response = $this->putJson(
        "/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar",
        ['url' => 'https://cdn.example/new.png'],
    );

    $response->assertOk();
    expect($response->json('data.url'))->toBe('https://cdn.example/new.png');

    $avatar = MOAvatarModel::where('imageable_id', $user->id)
        ->where('imageable_type', MOUserModel::class)
        ->first();
    expect($avatar->url)->toBe('https://cdn.example/new.png');
});

it('returns 404 when updating a morph-one that has not been created yet', function () {
    $user = MOUserModel::create(['name' => 'Empty']);

    $response = $this->putJson(
        "/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar",
        ['url' => 'https://cdn.example/ghost.png'],
    );

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Destroy — removes the morph-one without affecting siblings
// ---------------------------------------------------------------------------

it('deletes the existing morph-one model', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    MOAvatarModel::create([
        'url' => 'https://cdn.example/alice.png',
        'imageable_type' => MOUserModel::class,
        'imageable_id' => $user->id,
    ]);

    $response = $this->deleteJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar");

    $response->assertOk();
    expect(MOAvatarModel::where('imageable_id', $user->id)
        ->where('imageable_type', MOUserModel::class)
        ->exists())->toBeFalse();
});

it('does not delete a morph-one belonging to another morph type', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    $team = MOTeamModel::create(['name' => 'Engineers']);
    MOAvatarModel::create([
        'url' => 'https://cdn.example/team.png',
        'imageable_type' => MOTeamModel::class,
        'imageable_id' => $team->id,
    ]);

    // Calling delete from the User side must not touch the Team's avatar.
    $response = $this->deleteJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/avatar");

    expect($response->status())->toBeIn([200, 404]);
    expect(MOAvatarModel::where('imageable_type', MOTeamModel::class)
        ->where('imageable_id', $team->id)
        ->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

it('returns 404 for unknown parent resource on morph-one', function () {
    $response = $this->getJson('/martis/api/resources/nonexistent/1/morph-one/avatar');
    $response->assertStatus(404);
});

it('returns 404 for unknown parent record on morph-one', function () {
    $response = $this->getJson('/martis/api/resources/m-o-user-models/99999/morph-one/avatar');
    $response->assertStatus(404);
});

it('returns 404 for unknown morph-one relationship name', function () {
    $user = MOUserModel::create(['name' => 'Alice']);
    $response = $this->getJson("/martis/api/resources/m-o-user-models/{$user->id}/morph-one/nonexistent");
    $response->assertStatus(404);
});
