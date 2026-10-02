<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Concerns\HasGate;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The soft gate looks for the lock of the card a route names, so only a route
 * with a `{card}` builds the resource's `cards()`: every other route of the
 * resource (index, show, writes) neither pays for them nor breaks when they
 * throw.
 */

class CbdItem extends Model
{
    protected $table = 'cbd_items';

    protected $guarded = [];

    public $timestamps = false;
}

class CbdOpenMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(CbdItem::query()->count());
    }
}

class CbdPaidMetric extends CbdOpenMetric
{
    use HasGate;

    public function __construct()
    {
        parent::__construct('Paid', 'paid');
        $this->lockedFor(fn (): bool => true)->lockModal(['title' => 'Pro feature', 'message' => 'Upgrade to unlock.']);
    }
}

class CbdItemResource extends Resource
{
    public static int $cardsBuilt = 0;

    public static bool $cardsThrow = false;

    public static function model(): string
    {
        return CbdItem::class;
    }

    public static function uriKey(): string
    {
        return 'cbd-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function cards(Request $request): array
    {
        self::$cardsBuilt++;

        if (self::$cardsThrow) {
            throw new RuntimeException('cards() is broken');
        }

        return [CbdOpenMetric::make('Open', 'open'), new CbdPaidMetric];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Schema::dropIfExists('cbd_items');
    Schema::create('cbd_items', function ($table) {
        $table->id();
        $table->string('name');
    });
    CbdItem::create(['name' => 'Ada']);
    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(CbdItemResource::class);
    CbdItemResource::$cardsBuilt = 0;
    CbdItemResource::$cardsThrow = false;
    Cache::flush();
});

afterEach(function () {
    CbdItemResource::$cardsThrow = false;
    Schema::dropIfExists('cbd_items');
});

it('does not build the cards of a resource on a route that names no card', function () {
    $this->getJson('/martis/api/resources/cbd-items')->assertOk();
    $this->getJson('/martis/api/resources/cbd-items/1')->assertOk();
    $this->putJson('/martis/api/resources/cbd-items/1', ['name' => 'Ada L.'])->assertOk();

    expect(CbdItemResource::$cardsBuilt)->toBe(0);
});

it('does not turn a throwing cards() into a 500 on the routes that name no card', function () {
    CbdItemResource::$cardsThrow = true;

    $this->getJson('/martis/api/resources/cbd-items')->assertOk();
    $this->getJson('/martis/api/resources/cbd-items/1')->assertOk();
});

it('still locks the card route of a locked card, and serves the open one', function () {
    $this->getJson('/martis/api/resources/cbd-items/cards/paid')
        ->assertForbidden()
        ->assertJsonPath('locked', true)
        ->assertJsonPath('lock.modal.title', 'Pro feature');

    $this->getJson('/martis/api/resources/cbd-items/cards/open')->assertOk();

    expect(CbdItemResource::$cardsBuilt)->toBeGreaterThan(0);
});
