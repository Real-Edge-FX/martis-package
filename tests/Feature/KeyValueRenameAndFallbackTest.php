<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\KeyValue;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * `fill()` holds a KeyValue payload to the key set its `disable*` flags fix,
 * and holds it to what the form lets a user do, no more: with only
 * `disableAddingRows()` a user can still rename a key and delete a row, and
 * with every flag on a record that has no stored map is held to the
 * `default()` keys instead of dropping every edit.
 */

class KvsItem extends Model
{
    protected $table = 'kvs_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['no_adding' => 'array', 'no_keys' => 'array', 'fixed' => 'array', 'no_adding_no_deleting' => 'array', 'no_deleting' => 'array'];
}

class KvsResource extends Resource
{
    public static function model(): string
    {
        return KvsItem::class;
    }

    public static function uriKey(): string
    {
        return 'kvs-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->nullable(),
            KeyValue::make('no_adding')->disableAddingRows()->nullable(),
            KeyValue::make('no_keys')->disableEditingKeys()->nullable(),
            KeyValue::make('no_deleting')->disableDeletingRows()->nullable(),
            KeyValue::make('no_adding_no_deleting')->disableAddingRows()->disableDeletingRows()->nullable(),
            KeyValue::make('fixed')->default(['mon' => '9-17', 'tue' => '9-17'])->disableEditingKeys()->disableAddingRows()->disableDeletingRows()->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Schema::dropIfExists('kvs_items');
    Schema::create('kvs_items', function ($t) {
        $t->id();
        $t->string('title')->nullable();
        foreach (['no_adding', 'no_keys', 'no_deleting', 'no_adding_no_deleting', 'fixed'] as $column) {
            $t->json($column)->nullable();
        }
    });
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(KvsResource::class);
});

afterEach(function () {
    Schema::dropIfExists('kvs_items');
});

function kvsPut(string $column, array $stored, array $submitted): ?array
{
    $id = KvsItem::query()->create(['title' => 't', $column => $stored])->id;

    test()->putJson("/martis/api/resources/kvs-items/{$id}", [$column => $submitted])->assertOk();

    return KvsItem::query()->find($id)->{$column};
}

it('lets a user rename a key when only adding rows is disabled', function () {
    // The form offers a key edit (and a delete) here; only "add row" is gone.
    expect(kvsPut('no_adding', ['a' => '1', 'b' => '2'], ['a' => '1', 'renamed' => '2']))
        ->toBe(['a' => '1', 'renamed' => '2']);
});

it('lets a user delete a row when only adding rows is disabled', function () {
    expect(kvsPut('no_adding', ['a' => '1', 'b' => '2'], ['a' => '1']))->toBe(['a' => '1']);
});

it('still drops a row added beyond the stored count when adding rows is disabled', function () {
    // One row renamed, two new: the rename takes the free slot, the surplus is dropped.
    expect(kvsPut('no_adding', ['a' => '1', 'b' => '2'], ['a' => '1', 'x' => '2', 'y' => '3']))
        ->toBe(['a' => '1', 'x' => '2']);

    // Nothing missing: a new row has no slot at all.
    expect(kvsPut('no_adding', ['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '2', 'c' => '3']))
        ->toBe(['a' => '1', 'b' => '2']);
});

it('keeps the values of the keys in the set as the user sends them', function () {
    expect(kvsPut('no_adding', ['a' => '1', 'b' => '2'], ['a' => 'changed', 'b' => '2']))->toBe(['a' => 'changed', 'b' => '2']);
});

it('drops a renamed key and a new one when editing keys is disabled', function () {
    expect(kvsPut('no_keys', ['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '3', 'c' => 'new']))->toBe(['a' => '1', 'b' => '3']);
    expect(kvsPut('no_keys', ['a' => '1', 'b' => '2'], ['a' => '1', 'renamed' => '2']))->toBe(['a' => '1']);
});

it('gives a deleted row back when deleting rows is disabled, and takes a rename as a rename when adding is disabled too', function () {
    expect(kvsPut('no_deleting', ['a' => '1', 'b' => '2'], ['a' => '1']))->toBe(['a' => '1', 'b' => '2']);

    // A rename keeps the number of rows: the old key does not come back beside the new one.
    expect(kvsPut('no_adding_no_deleting', ['a' => '1', 'b' => '2'], ['a' => '1', 'renamed' => '2']))
        ->toBe(['a' => '1', 'renamed' => '2']);

    // Adding and deleting disabled: a row added while none was renamed is dropped.
    expect(kvsPut('no_adding_no_deleting', ['a' => '1', 'b' => '2'], ['a' => '1', 'b' => '2', 'c' => '3']))
        ->toBe(['a' => '1', 'b' => '2']);
});

it('holds a record with no stored map to the default keys, and saves its edits', function () {
    $id = KvsItem::query()->create(['title' => 't'])->id;

    $this->putJson("/martis/api/resources/kvs-items/{$id}", ['fixed' => ['mon' => '10-16', 'sun' => 'open', 'renamed' => 'x']])->assertOk();

    expect(KvsItem::query()->find($id)->fixed)->toBe(['mon' => '10-16', 'tue' => '9-17']);
});

it('falls back to the default keys for a record whose stored map is empty', function () {
    $id = KvsItem::query()->create(['title' => 't', 'fixed' => []])->id;

    $this->putJson("/martis/api/resources/kvs-items/{$id}", ['fixed' => ['tue' => 'closed']])->assertOk();

    expect(KvsItem::query()->find($id)->fixed)->toBe(['mon' => '9-17', 'tue' => 'closed']);
});

it('keeps the stored keys, not the default ones, for a record that has a map', function () {
    expect(kvsPut('fixed', ['x' => '1'], ['x' => '2', 'mon' => 'new']))->toBe(['x' => '2']);
});

it('stores any key for a field without these flags', function () {
    $id = KvsItem::query()->create(['title' => 't'])->id;
    $this->putJson("/martis/api/resources/kvs-items/{$id}", ['no_deleting' => ['whatever' => 'x']])->assertOk();

    expect(KvsItem::query()->find($id)->no_deleting)->toBe(['whatever' => 'x']);
});
