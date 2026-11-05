<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\Boolean;
use Martis\Fields\File;
use Martis\Fields\MultiSelect;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * v2.17.0: an action modal holding a file posts the run as multipart, and
 * the upload reaches handle() as an UploadedFile, validated by the File
 * field's own rules. A queued action refuses an upload.
 */

class AFFItem extends Model
{
    protected $table = 'aff_items';

    protected $fillable = ['title'];
}

class AFFLog
{
    /** @var array<string, mixed> */
    public static array $got = [];
}

class AFFAddVersion extends Action
{
    public function fields(Request $request): array
    {
        return [
            Text::make('title')->rules(['required']),
            File::make('file')->acceptedTypes(['txt'])->rules(['required']),
            Boolean::make('notify'),
            MultiSelect::make('locales')->options(['pt' => 'PT', 'en' => 'EN']),
        ];
    }

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        $file = $fields->get('file');
        AFFLog::$got = [
            'title' => $fields->get('title'),
            'file' => $file instanceof UploadedFile ? $file->getClientOriginalName() : get_debug_type($file),
            'contents' => $file instanceof UploadedFile ? $file->getContent() : null,
            'notify' => $fields->get('notify'),
            'locales' => $fields->get('locales'),
            'models' => $models->pluck('id')->all(),
        ];

        return ActionResponse::message('Added.');
    }

    public function uriKey(): string
    {
        return 'aff-add-version';
    }
}

class AFFQueuedImport extends AFFAddVersion implements ShouldQueue
{
    public function uriKey(): string
    {
        return 'aff-queued-import';
    }
}

class AFFResource extends Resource
{
    public static function model(): string
    {
        return AFFItem::class;
    }

    public static function uriKey(): string
    {
        return 'aff-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function actions(Request $request): array
    {
        return [new AFFAddVersion, new AFFQueuedImport];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    AFFLog::$got = [];

    Schema::dropIfExists('aff_items');
    Schema::create('aff_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AFFResource::class);
});

afterEach(fn () => Schema::dropIfExists('aff_items'));

/** A multipart run, as the modal sends it (`buildFormData()` encoding). */
function affMultipartRun($test, string $action, array $parts)
{
    // post() moves the UploadedFile values into the request's files, as a
    // browser's multipart body does.
    return $test->withHeaders(['Accept' => 'application/json'])
        ->post("/martis/api/resources/aff-items/actions/{$action}", array_replace_recursive($parts['data'], $parts['files']));
}

it('hands the upload to handle() as an UploadedFile, with the other values decoded', function () {
    $item = AFFItem::create(['title' => 'Spring guide']);

    affMultipartRun($this, 'aff-add-version', [
        'data' => ['resources' => [(string) $item->id], 'dryRun' => '0', 'fields' => ['title' => 'English', 'notify' => '1', 'locales' => '["en"]']],
        'files' => ['fields' => ['file' => UploadedFile::fake()->createWithContent('guide.txt', 'hello')]],
    ])->assertOk();

    expect(AFFLog::$got)->toMatchArray([
        'title' => 'English',
        'file' => 'guide.txt',
        'contents' => 'hello',
        'notify' => '1',
        'locales' => ['en'],
        'models' => [$item->id],
    ]);
});

it('validates the File field with its own rules', function () {
    $item = AFFItem::create(['title' => 'Spring guide']);

    affMultipartRun($this, 'aff-add-version', [
        'data' => ['resources' => [(string) $item->id], 'fields' => ['title' => 'English']],
        'files' => ['fields' => ['file' => UploadedFile::fake()->create('cover.exe', 1)]],
    ])->assertStatus(422);

    expect(AFFLog::$got)->toBe([]);
});

it('still runs a JSON request with no file as before', function () {
    $item = AFFItem::create(['title' => 'Spring guide']);

    $this->postJson('/martis/api/resources/aff-items/actions/aff-add-version', [
        'resources' => [$item->id],
        'fields' => ['title' => 'English'],
    ])->assertStatus(422);

    expect(AFFLog::$got)->toBe([]);
});

it('refuses an upload on a queued action, naming the action and the field', function () {
    Queue::fake();
    $this->withoutExceptionHandling();
    $item = AFFItem::create(['title' => 'Spring guide']);

    affMultipartRun($this, 'aff-queued-import', [
        'data' => ['resources' => [(string) $item->id], 'fields' => ['title' => 'English']],
        'files' => ['fields' => ['file' => UploadedFile::fake()->createWithContent('guide.txt', 'hello')]],
    ]);
})->throws(LogicException::class, 'AFFQueuedImport is queued, so its field [file] cannot take an uploaded file');
