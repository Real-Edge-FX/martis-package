<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\Markdown;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Fields\Trix;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\RouteMiddleware;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The rich text attachment upload names the resource, the field and (editing)
 * the record, and is authorised like the form it comes from: the user may
 * list the resource and create it (or update the record), and the field is on
 * that form, visible to the user, and declares withFiles(). The file goes to
 * the disk the field declares, never to one the request names. A dedicated
 * per-user throttle bounds the volume.
 */

class AttUploadModel extends Model
{
    protected $table = 'att_upload_items';

    protected $guarded = [];

    public $timestamps = false;
}

class AttUploadPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function view($user, $model): bool
    {
        return true;
    }

    /** Only a user whose email starts with "writer" may create. */
    public function create($user): bool
    {
        return str_starts_with((string) $user->email, 'writer');
    }

    /** Record 1 may be updated by the users whose email starts with "editor" or "writer". */
    public function update($user, $model): bool
    {
        return (int) $model->getKey() === 1
            && (str_starts_with((string) $user->email, 'editor') || str_starts_with((string) $user->email, 'writer'));
    }
}

class AttUploadBlock extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Trix::make('text')->withFiles('public')];
    }
}

class AttUploadResource extends Resource
{
    public static ?string $policy = AttUploadPolicy::class;

    public static function model(): string
    {
        return AttUploadModel::class;
    }

    public static function uriKey(): string
    {
        return 'att-uploads';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            Trix::make('body')->withFiles('public'),
            Trix::make('plain'),
            Markdown::make('notes')->withFiles('local'),
            Trix::make('secret')->withFiles('public')->canSee(fn () => false),
            Trix::make('default_disk')->withFiles(),
            Repeater::make('blocks')->repeatables([AttUploadBlock::make()]),
        ];
    }

    public function fieldsForCreate(Request $request): array
    {
        return [
            ...$this->fields($request),
            Trix::make('create_only')->withFiles('public'),
        ];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            ...$this->fields($request),
            Trix::make('update_only')->withFiles('public'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Storage::fake('public');
    Storage::fake('local');
    config()->set('martis.storage.disk', 'public');

    Schema::dropIfExists('att_upload_items');
    Schema::create('att_upload_items', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->text('body')->nullable();
    });
    AttUploadModel::create(['title' => 'One']);
    AttUploadModel::create(['title' => 'Two']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AttUploadResource::class);
});

afterEach(function () {
    Schema::dropIfExists('att_upload_items');
    Resource::flushPolicyCache();
});

function attActingAs($test, string $email): void
{
    $test->actingAs((new Authenticatable)->forceFill(['id' => 7, 'name' => 'User', 'email' => $email]));
}

/** @return list<string> */
function attFiles(string $disk): array
{
    return Storage::disk($disk)->allFiles();
}

function attUpload($test, string $query, ?UploadedFile $file = null)
{
    return $test->postJson('/martis/api/attachments/upload?'.$query, [
        'file' => $file ?? UploadedFile::fake()->image('photo.jpg', 200, 200),
    ]);
}

it('refuses the upload of a user who may only list the resource, and stores nothing', function () {
    attActingAs($this, 'reader@test.local');

    foreach (['', '&id=1', '&id=2', '&id=_', '&id=999'] as $id) {
        attUpload($this, 'resource=att-uploads&field=body'.$id)->assertForbidden();
    }

    expect(attFiles('public'))->toBe([])->and(attFiles('local'))->toBe([]);
});

it('stores the upload of a user who may create on the create form, on the field disk', function () {
    attActingAs($this, 'writer@test.local');

    $response = attUpload($this, 'resource=att-uploads&field=body')->assertOk()->assertJsonStructure(['url', 'href']);

    $files = attFiles('public');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith('martis-attachments/')
        ->and($response->json('url'))->toContain(basename($files[0]));
});

it('takes the disk from the field, never from the request', function () {
    attActingAs($this, 'writer@test.local');

    // A Markdown field on the local disk, with a request asking for public.
    attUpload($this, 'resource=att-uploads&field=notes&disk=public')->assertOk();
    expect(attFiles('local'))->toHaveCount(1)->and(attFiles('public'))->toBe([]);

    // A Trix field on the public disk, with a request asking for local.
    attUpload($this, 'resource=att-uploads&field=body&disk=local')->assertOk();
    expect(attFiles('public'))->toHaveCount(1)->and(attFiles('local'))->toHaveCount(1);
});

it('stores a field that declares no disk on the panel storage disk', function () {
    config()->set('martis.storage.disk', 'local');
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=att-uploads&field=default_disk')->assertOk();

    expect(attFiles('local'))->toHaveCount(1)->and(attFiles('public'))->toBe([]);
});

it('refuses a field that does not declare withFiles()', function () {
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=att-uploads&field=plain')->assertForbidden();

    expect(attFiles('public'))->toBe([]);
});

it('answers 404 for a field the form does not have, or the user cannot see', function (string $field) {
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=att-uploads&field='.$field)->assertNotFound();

    expect(attFiles('public'))->toBe([]);
})->with(['an unknown field' => 'nope', 'a hidden field' => 'secret', 'a text field' => 'title', 'a field of the update form only' => 'update_only']);

it('answers 404 for an unknown resource and 422 for a request that names no field', function () {
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=nope&field=body')->assertNotFound();
    attUpload($this, 'resource=att-uploads')->assertStatus(422)->assertJsonValidationErrors('field');
    attUpload($this, 'field=body')->assertStatus(422)->assertJsonValidationErrors('resource');
    expect(attFiles('public'))->toBe([]);
});

it('uses the update form for a record the user may update, with no create ability', function () {
    attActingAs($this, 'editor@test.local');

    // Record 1: the update form (which has `update_only`, and not `create_only`).
    attUpload($this, 'resource=att-uploads&field=update_only&id=1')->assertOk();
    attUpload($this, 'resource=att-uploads&field=create_only&id=1')->assertNotFound();

    // Record 2 (not updatable) and no id fall to the create form, which this user may not use.
    attUpload($this, 'resource=att-uploads&field=body&id=2')->assertForbidden();
    attUpload($this, 'resource=att-uploads&field=body')->assertForbidden();

    expect(attFiles('public'))->toHaveCount(1);
});

it('uses the create form for a user who may create, and only the fields it has', function () {
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=att-uploads&field=create_only')->assertOk();
    attUpload($this, 'resource=att-uploads&field=update_only')->assertNotFound();
});

it('uploads from the Trix field of a Repeater row, and only from the row the request names', function () {
    attActingAs($this, 'writer@test.local');

    attUpload($this, 'resource=att-uploads&field=text&repeater=blocks&repeatable=att-upload-block')->assertOk();
    attUpload($this, 'resource=att-uploads&field=text&repeater=blocks&repeatable=other-row')->assertNotFound();
    attUpload($this, 'resource=att-uploads&field=text')->assertNotFound();

    expect(attFiles('public'))->toHaveCount(1);
});

it('validates the file after authorising the user', function () {
    attActingAs($this, 'reader@test.local');

    // A forbidden user learns nothing about the file rules.
    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('run.exe', 10))->assertForbidden();

    attActingAs($this, 'writer@test.local');
    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('run.exe', 10))->assertStatus(422)->assertJsonValidationErrors('file');
    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml'))->assertStatus(422);

    config()->set('martis.attachments.max_size', 1);
    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('big.pdf', 50, 'application/pdf'))->assertStatus(422);

    $this->postJson('/martis/api/attachments/upload?resource=att-uploads&field=body', [])->assertStatus(422);

    expect(attFiles('public'))->toBe([]);
});

it('carries a dedicated per-user throttle on the upload route', function () {
    $route = Route::getRoutes()->getByName('martis.api.attachments.upload');

    expect($route->gatherMiddleware())->toContain('throttle:20,1,martis-attachments:web:');
});

it('builds the upload throttle from the attachments config, and drops it with the API throttle', function () {
    config()->set('martis.attachments.throttle_max', 3);
    config()->set('martis.attachments.throttle_decay', 5);
    expect(RouteMiddleware::attachmentUpload())->toBe(['throttle:3,5,martis-attachments:web:']);

    config()->set('martis.attachments.throttle_max', null);
    config()->set('martis.attachments.throttle_decay', null);
    expect(RouteMiddleware::attachmentUpload())->toBe(['throttle:20,1,martis-attachments:web:']);

    config()->set('martis.throttle.enabled', false);
    expect(RouteMiddleware::attachmentUpload())->toBe([]);
});

it('answers 429 once a user passes the upload limit, apart from other users', function () {
    attActingAs($this, 'writer@test.local');

    for ($i = 0; $i < 20; $i++) {
        attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create("n{$i}.txt", 1, 'text/plain'))->assertOk();
    }

    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('over.txt', 1, 'text/plain'))->assertStatus(429);
    expect(attFiles('public'))->toHaveCount(20);

    // Another account has a bucket of its own.
    $this->actingAs((new Authenticatable)->forceFill(['id' => 8, 'name' => 'Other', 'email' => 'writer2@test.local']));
    attUpload($this, 'resource=att-uploads&field=body', UploadedFile::fake()->create('other.txt', 1, 'text/plain'))->assertOk();
});
