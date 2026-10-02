<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BooleanGroup;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * `minChecked()` / `maxChecked()` count the flags `fill()` stores: the
 * submitted keys the field offers, each read as a boolean the way `fill()`
 * reads it ('on', 'yes', 'TRUE' are on), and nothing else. A second, stricter
 * count in the rule let `maxChecked(1)` be bypassed with `'on'` values and
 * `minChecked(1)` with a key the field does not offer.
 */

class BgcItem extends Model
{
    protected $table = 'bgc_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['perms' => 'array', 'minperms' => 'array'];
}

class BgcResource extends Resource
{
    public static function model(): string
    {
        return BgcItem::class;
    }

    public static function uriKey(): string
    {
        return 'bgc-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->nullable(),
            BooleanGroup::make('perms')->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])->maxChecked(1)->nullable(),
            BooleanGroup::make('minperms')->options(['a' => 'A', 'b' => 'B'])->minChecked(1)->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Schema::dropIfExists('bgc_items');
    Schema::create('bgc_items', function ($t) {
        $t->id();
        $t->string('title')->nullable();
        $t->json('perms')->nullable();
        $t->json('minperms')->nullable();
    });
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(BgcResource::class);
});

afterEach(function () {
    Schema::dropIfExists('bgc_items');
});

it('counts the truthy spellings fill() stores toward maxChecked', function (array $perms) {
    $this->postJson('/martis/api/resources/bgc-items', ['perms' => $perms])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'perms');

    expect(BgcItem::query()->count())->toBe(0);
})->with([
    'on, yes, on' => [['a' => 'on', 'b' => 'yes', 'c' => 'on']],
    'TRUE, True, true' => [['a' => 'TRUE', 'b' => 'True', 'c' => 'true']],
    'booleans' => [['a' => true, 'b' => true]],
    '1 and "1"' => [['a' => 1, 'b' => '1']],
    'one on, one yes' => [['a' => 'on', 'b' => 'yes']],
]);

it('does not count a key the field does not offer toward minChecked', function () {
    $this->postJson('/martis/api/resources/bgc-items', ['minperms' => ['a' => false, 'b' => false, 'junk' => true]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'minperms');

    expect(BgcItem::query()->count())->toBe(0);
});

it('does not count a key the field does not offer toward maxChecked either', function () {
    // One offered flag on, two unknown keys on: fill() stores one flag.
    $this->postJson('/martis/api/resources/bgc-items', ['perms' => ['a' => true, 'junk' => true, 'more' => true]])
        ->assertCreated();

    expect(BgcItem::query()->first()->perms)->toBe(['a' => true, 'b' => false, 'c' => false]);
});

it('keeps accepting the counts the field allows, in every spelling', function (array $perms, array $stored) {
    $this->postJson('/martis/api/resources/bgc-items', ['perms' => $perms, 'minperms' => ['a' => 'yes']])
        ->assertCreated();

    expect(BgcItem::query()->first()->perms)->toBe($stored)
        ->and(BgcItem::query()->first()->minperms)->toBe(['a' => true, 'b' => false]);
})->with([
    'one on' => [['a' => 'on'], ['a' => true, 'b' => false, 'c' => false]],
    'one true, others false or off' => [['a' => false, 'b' => 'true', 'c' => 'off'], ['a' => false, 'b' => true, 'c' => false]],
    'none' => [[], ['a' => false, 'b' => false, 'c' => false]],
]);

it('counts the same set on update, and when the value arrives as a JSON string', function () {
    $item = BgcItem::query()->create(['title' => 't', 'perms' => ['a' => true]]);

    $this->putJson("/martis/api/resources/bgc-items/{$item->id}", ['perms' => ['a' => 'on', 'b' => 'yes']])
        ->assertStatus(422);
    $this->putJson("/martis/api/resources/bgc-items/{$item->id}", ['perms' => json_encode(['a' => 'on', 'b' => 'yes'])])
        ->assertStatus(422);

    expect($item->fresh()->perms)->toBe(['a' => true]);
});
