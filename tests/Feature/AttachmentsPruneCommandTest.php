<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * `martis:attachments:prune` deletes the files under `martis-attachments/`
 * that no record references and that were written more than `--hours` ago.
 * The references are the stored file names in the text and JSON columns of the
 * table of every registered resource's model.
 */

class AttPruneModel extends Model
{
    protected $table = 'att_prune_items';

    protected $guarded = [];

    public $timestamps = false;
}

class AttPruneResource extends Resource
{
    public static function model(): string
    {
        return AttPruneModel::class;
    }

    public static function uriKey(): string
    {
        return 'att-prunes';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class AttPruneUnreachableModel extends Model
{
    protected $connection = 'no-such-connection';

    protected $table = 'att_prune_items';
}

class AttPruneUnreachableResource extends AttPruneResource
{
    public static function model(): string
    {
        return AttPruneUnreachableModel::class;
    }

    public static function uriKey(): string
    {
        return 'att-prune-unreachable';
    }
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('other');
    config()->set('martis.storage.disk', 'public');

    Schema::dropIfExists('att_prune_items');
    Schema::create('att_prune_items', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->text('body')->nullable();
        $table->json('blocks')->nullable();
        $table->integer('count')->default(0);
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AttPruneResource::class);
});

afterEach(function () {
    Schema::dropIfExists('att_prune_items');
});

/** Put a file the upload endpoint would have written, aged `$hoursOld` hours. */
function attPruneFile(string $disk, int $hoursOld, string $ext = 'png', ?string $name = null): string
{
    $name ??= Str::random(40).'.'.$ext;
    $path = 'martis-attachments/'.$name;
    Storage::disk($disk)->put($path, 'content');
    touch(Storage::disk($disk)->path($path), time() - $hoursOld * 3600);

    return $path;
}

it('deletes the old files no record references, and keeps every other', function () {
    $orphan = attPruneFile('public', 48);
    $recent = attPruneFile('public', 1);
    $inBody = attPruneFile('public', 48);
    $inJson = attPruneFile('public', 48, 'pdf');
    $inTrashed = attPruneFile('public', 48, 'jpg');
    $notOurs = attPruneFile('public', 48, 'png', 'handmade-logo.png');
    $shortStem = attPruneFile('public', 48, 'png', Str::random(12).'.png');

    AttPruneModel::create(['title' => 'a', 'body' => '<div><img src="/storage/'.$inBody.'"></div>']);
    // A Repeater row's Trix content, JSON escapes the slashes around the name.
    AttPruneModel::create(['blocks' => json_encode([['fields' => ['text' => '<img src="/storage/'.$inJson.'">']]])]);
    AttPruneModel::create(['body' => 'x', 'deleted_at' => now()])->forceFill(['body' => '![](/storage/'.$inTrashed.')'])->save();

    $this->artisan('martis:attachments:prune')->assertExitCode(0);

    expect(Storage::disk('public')->exists($orphan))->toBeFalse()
        ->and(Storage::disk('public')->exists($recent))->toBeTrue()
        ->and(Storage::disk('public')->exists($inBody))->toBeTrue()
        ->and(Storage::disk('public')->exists($inJson))->toBeTrue()
        ->and(Storage::disk('public')->exists($inTrashed))->toBeTrue()
        ->and(Storage::disk('public')->exists($notOurs))->toBeTrue()
        ->and(Storage::disk('public')->exists($shortStem))->toBeTrue();
});

it('only lists what it would delete with --dry-run', function () {
    $orphan = attPruneFile('public', 48);

    $this->artisan('martis:attachments:prune', ['--dry-run' => true])
        ->expectsOutputToContain('would delete '.$orphan)
        ->assertExitCode(0);

    expect(Storage::disk('public')->exists($orphan))->toBeTrue();
});

it('takes the age from --hours, and refuses less than an hour', function () {
    $young = attPruneFile('public', 3);

    $this->artisan('martis:attachments:prune', ['--hours' => 6])->assertExitCode(0);
    expect(Storage::disk('public')->exists($young))->toBeTrue();

    $this->artisan('martis:attachments:prune', ['--hours' => 2])->assertExitCode(0);
    expect(Storage::disk('public')->exists($young))->toBeFalse();

    $kept = attPruneFile('public', 48);
    $this->artisan('martis:attachments:prune', ['--hours' => 0])->assertExitCode(1);
    expect(Storage::disk('public')->exists($kept))->toBeTrue();
});

it('sweeps the disks --disk names instead of the panel disk', function () {
    $onPublic = attPruneFile('public', 48);
    $onOther = attPruneFile('other', 48);

    $this->artisan('martis:attachments:prune', ['--disk' => ['other']])->assertExitCode(0);

    expect(Storage::disk('other')->exists($onOther))->toBeFalse()
        ->and(Storage::disk('public')->exists($onPublic))->toBeTrue();
});

it('never deletes a file a record references through a column other than the form field', function () {
    $file = attPruneFile('public', 48);
    AttPruneModel::create(['title' => 'See /storage/'.$file]);

    $this->artisan('martis:attachments:prune')->assertExitCode(0);

    expect(Storage::disk('public')->exists($file))->toBeTrue();
});

it('stops without deleting when the records of a resource cannot be read', function () {
    $orphan = attPruneFile('public', 48);
    app(ResourceRegistry::class)->register(AttPruneUnreachableResource::class);

    $this->artisan('martis:attachments:prune')->assertExitCode(1);

    expect(Storage::disk('public')->exists($orphan))->toBeTrue();
});
