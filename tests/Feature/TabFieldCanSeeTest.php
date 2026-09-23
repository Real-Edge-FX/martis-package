<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Panel;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// canSee() on a field placed directly in a Tab (v1.38.0).
//
// Tab::filterForContext() applied the context rules to the fields it holds
// directly and skipped canSee(), unlike Panel and Section, so a field the
// user cannot see was serialised in the schema, its value was sent with the
// record, and the create and the update validated it and wrote the value the
// request sent. It is now left out like a hidden field anywhere else.
// ===========================================================================

class TFCArticle extends Model
{
    protected $table = 'tfc_articles';

    protected $guarded = [];

    public $timestamps = false;
}

class TFCArticleResource extends Resource
{
    public static function model(): string
    {
        return TFCArticle::class;
    }

    public static function uriKey(): string
    {
        return 'tfc-articles';
    }

    public function fields(Request $request): array
    {
        return [
            TabGroup::make([
                Tab::make('Main', [
                    Text::make('title')->nullable(),
                    Text::make('secret')->canSee(fn () => false)->rules(['required', 'max:3']),
                    Panel::make('More', [
                        Text::make('note')->nullable(),
                        Text::make('hint')->canSee(fn () => false),
                    ]),
                ]),
            ]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('tfc_articles');
    Schema::create('tfc_articles', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->string('secret')->nullable();
        $table->string('note')->nullable();
        $table->string('hint')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(TFCArticleResource::class);
});

afterEach(function () {
    Schema::dropIfExists('tfc_articles');
});

/**
 * The attributes of the fields a serialised layout holds, at every depth.
 *
 * @param  array<array-key, mixed>  $items
 * @return list<string>
 */
function tfcAttributes(array $items): array
{
    $attributes = [];

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        if (isset($item['attribute']) && is_string($item['attribute'])) {
            $attributes[] = $item['attribute'];
        }

        foreach (['tabs', 'fields'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                $attributes = [...$attributes, ...tfcAttributes($item[$key])];
            }
        }
    }

    return $attributes;
}

it('leaves a field the user cannot see out of a Tab in every form of the schema', function (string $form) {
    $schema = $this->getJson('/martis/api/resources/tfc-articles/schema')->assertOk();

    expect(tfcAttributes($schema->json("data.{$form}")))->toBe(['title', 'note']);
})->with(['fieldsForIndex', 'fieldsForDetail', 'fieldsForCreate', 'fieldsForUpdate']);

it('sends no value of a field in a Tab the user cannot see', function (string $query) {
    $article = TFCArticle::create(['title' => 'Hello', 'secret' => 'abc', 'note' => 'n', 'hint' => 'h']);

    $data = $this->getJson("/martis/api/resources/tfc-articles/{$article->id}{$query}")->assertOk()->json('data');

    expect($data)->toHaveKeys(['title', 'note'])
        ->and($data)->not->toHaveKey('secret')
        ->and($data)->not->toHaveKey('hint');

    $index = $this->getJson('/martis/api/resources/tfc-articles')->assertOk()->json('data.0');

    expect($index)->toHaveKey('title')
        ->and($index)->not->toHaveKey('secret');
})->with(['detail' => '', 'update form' => '?context=update']);

it('neither validates nor writes a field in a Tab the user cannot see', function () {
    $article = TFCArticle::create(['title' => 'Hello', 'secret' => 'abc']);

    $this->putJson("/martis/api/resources/tfc-articles/{$article->id}", [
        'title' => 'Changed',
        'secret' => 'forged value',
        'hint' => 'forged',
    ])->assertOk();

    expect($article->fresh()->only(['title', 'secret', 'hint']))->toBe(['title' => 'Changed', 'secret' => 'abc', 'hint' => null]);

    // `secret` is required, but the create form never shows it.
    $this->postJson('/martis/api/resources/tfc-articles', ['title' => 'New', 'secret' => 'forged value'])->assertStatus(201);

    expect(TFCArticle::where('title', 'New')->sole()->secret)->toBeNull();
});
