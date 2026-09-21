<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Boolean;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Select;
use Martis\Fields\Text;

// ===========================================================================
// Repeatable::title(Closure) is resolved per row on the server.
//
// The Closure form of `title()` was serialised as `hasTitleCallback: true`
// but `resolveTitle()` had no caller, so every row header fell back to the
// repeatable's label. Each storage mode now ships the resolved string as
// the row's `title`, computed against the row's field values and its
// 1-based index; rows of a repeatable without a Closure keep their
// `{id, type, fields}` shape, and `fill()` never stores the derived key.
// ===========================================================================

class RCTSection extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Select::make('key', 'Section')->options(['hero' => 'Hero', 'stats' => 'Stats']),
            Boolean::make('enabled', 'Enabled'),
        ];
    }
}

class RCTPlain extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('headline', 'Headline')];
    }
}

class RCTPageModel extends Model
{
    protected $table = 'rct_pages';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['sections' => 'array'];

    public function blocks(): EloquentHasMany
    {
        return $this->hasMany(RCTBlockModel::class, 'page_id');
    }

    public function items(): EloquentHasMany
    {
        return $this->hasMany(RCTItemModel::class, 'page_id');
    }
}

class RCTBlockModel extends Model
{
    protected $table = 'rct_blocks';

    protected $guarded = [];

    public $timestamps = false;
}

class RCTHeadlineBlock extends Repeatable
{
    public static ?string $model = RCTBlockModel::class;

    public function fields(Request $request): array
    {
        return [Text::make('headline', 'Headline')];
    }
}

class RCTItemModel extends Model
{
    protected $table = 'rct_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['payload' => 'array'];
}

class RCTPolyItem extends Repeatable
{
    public static ?string $model = RCTItemModel::class;

    public function shortName(): string
    {
        return 'poly-item';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name', 'Name')];
    }
}

$titleClosure = fn (array $row, int $index): string => sprintf('%s #%d', ['hero' => 'Hero', 'stats' => 'Stats'][$row['key'] ?? ''] ?? 'Section', $index);

beforeEach(function () {
    Schema::dropIfExists('rct_items');
    Schema::dropIfExists('rct_blocks');
    Schema::dropIfExists('rct_pages');
    Schema::create('rct_pages', function ($table) {
        $table->id();
        $table->json('sections')->nullable();
    });
    Schema::create('rct_blocks', function ($table) {
        $table->id();
        $table->unsignedBigInteger('page_id');
        $table->string('headline')->nullable();
        $table->unsignedInteger('position')->default(0);
    });
    Schema::create('rct_items', function ($table) {
        $table->id();
        $table->unsignedBigInteger('page_id');
        $table->string('type');
        $table->json('payload')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('rct_items');
    Schema::dropIfExists('rct_blocks');
    Schema::dropIfExists('rct_pages');
});

it('resolves a Closure title per JSON row with the row values and its 1-based index', function () use ($titleClosure) {
    $field = Repeater::make('sections')->repeatables([RCTSection::make()->title($titleClosure)]);
    $model = new RCTPageModel(['sections' => [
        ['id' => 'a', 'type' => 'r-c-t-section', 'fields' => ['key' => 'hero', 'enabled' => true]],
        ['id' => 'b', 'type' => 'r-c-t-section', 'fields' => ['key' => 'stats', 'enabled' => false]],
        ['id' => 'c', 'type' => 'r-c-t-section', 'fields' => ['key' => 'unknown', 'enabled' => true]],
    ]]);

    $rows = $field->resolve($model);

    expect(array_column($rows, 'title'))->toBe(['Hero #1', 'Stats #2', 'Section #3'])
        ->and($rows[0])->toHaveKeys(['id', 'type', 'fields', 'title']);
});

it('resolves a Closure title on a legacy flat JSON row', function () use ($titleClosure) {
    $field = Repeater::make('sections')->repeatables([RCTSection::make()->title($titleClosure)]);
    $model = new RCTPageModel(['sections' => [['key' => 'hero', 'enabled' => true]]]);

    expect($field->resolve($model)[0]['title'])->toBe('Hero #1');
});

it('ships a null title when the Closure returns null', function () {
    $field = Repeater::make('sections')->repeatables([RCTSection::make()->title(fn (array $row, int $i): ?string => null)]);
    $model = new RCTPageModel(['sections' => [['id' => 'a', 'type' => 'r-c-t-section', 'fields' => ['key' => 'hero']]]]);

    $row = $field->resolve($model)[0];

    expect($row)->toHaveKey('title')
        ->and($row['title'])->toBeNull();
});

it('keeps the {id, type, fields} row shape when the repeatable has no Closure title', function () {
    $field = Repeater::make('sections')->repeatables([
        RCTPlain::make()->title('{headline}'),
    ]);
    $model = new RCTPageModel(['sections' => [['id' => 'a', 'type' => 'r-c-t-plain', 'fields' => ['headline' => 'Hi']]]]);

    $row = $field->resolve($model)[0];

    expect(array_keys($row))->toBe(['id', 'type', 'fields'])
        ->and($field->toArray()['repeatables'][0]['titleTemplate'])->toBe('{headline}')
        ->and($field->toArray()['repeatables'][0]['hasTitleCallback'])->toBeFalse();
});

it('resolves the Closure of the repeatable that matches each row type in a multi-type repeater', function () use ($titleClosure) {
    $field = Repeater::make('sections')->repeatables([
        RCTPlain::make(),
        RCTSection::make()->title($titleClosure),
    ]);
    $model = new RCTPageModel(['sections' => [
        ['id' => 'a', 'type' => 'r-c-t-plain', 'fields' => ['headline' => 'Hi']],
        ['id' => 'b', 'type' => 'r-c-t-section', 'fields' => ['key' => 'stats']],
    ]]);

    $rows = $field->resolve($model);

    expect($rows[0])->not->toHaveKey('title')
        ->and($rows[1]['title'])->toBe('Stats #2');
});

it('never stores the derived title when filling JSON rows', function () use ($titleClosure) {
    $field = Repeater::make('sections')->repeatables([RCTSection::make()->title($titleClosure)]);
    $model = new RCTPageModel;

    $field->fill($model, [
        ['id' => 'a', 'type' => 'r-c-t-section', 'fields' => ['key' => 'hero'], 'title' => 'Hero #1'],
    ]);

    expect($model->sections[0])->not->toHaveKey('title')
        ->and($model->sections[0]['fields'])->toBe(['key' => 'hero']);
});

it('leaves a real `title` attribute of a legacy flat row alone on fill', function () {
    $field = Repeater::make('sections')->repeatables([RCTPlain::make()]);
    $model = new RCTPageModel;

    $field->fill($model, [['id' => 'a', 'title' => 'Keep me', 'headline' => 'Hi']]);

    expect($model->sections[0]['title'])->toBe('Keep me');
});

it('resolves a Closure title per HasMany row from the child model values', function () {
    $page = RCTPageModel::create();
    RCTBlockModel::create(['page_id' => $page->id, 'headline' => 'First', 'position' => 0]);
    RCTBlockModel::create(['page_id' => $page->id, 'headline' => 'Second', 'position' => 1]);

    $field = Repeater::make('blocks')
        ->asHasMany()
        ->reorderable()
        ->repeatables([RCTHeadlineBlock::make()->title(fn (array $row, int $i): string => "{$i}. {$row['headline']}")]);

    $rows = $field->resolve($page);

    expect(array_column($rows, 'title'))->toBe(['1. First', '2. Second']);
});

it('resolves a Closure title per Polymorphic row from the JSON payload', function () {
    $page = RCTPageModel::create();
    RCTItemModel::create(['page_id' => $page->id, 'type' => 'poly-item', 'payload' => ['name' => 'Alpha']]);

    $field = Repeater::make('items')
        ->asPolymorphic('type', 'payload')
        ->repeatables([RCTPolyItem::make()->title(fn (array $row, int $i): string => strtoupper($row['name']))]);

    expect($field->resolve($page)[0]['title'])->toBe('ALPHA');
});

it('exposes hasTitleCallback() on the Repeatable', function () use ($titleClosure) {
    expect(RCTSection::make()->title($titleClosure)->hasTitleCallback())->toBeTrue()
        ->and(RCTSection::make()->title('{key}')->hasTitleCallback())->toBeFalse()
        ->and(RCTSection::make()->hasTitleCallback())->toBeFalse();
});
