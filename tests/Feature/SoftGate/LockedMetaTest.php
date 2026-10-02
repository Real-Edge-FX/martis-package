<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Enums\FilterType;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Filters\Filter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

/*
 * The soft lock withholds an entity's data (`lockedFor()`, `requirePlan()`).
 * `meta` is the channel a custom Tool / Dashboard component reads its data
 * from, and a locked Filter carries its `options` and `meta`: like a locked
 * Card's `meta` they are left out of every payload that serves the entity,
 * and the cached payloads that hold them follow the lock state.
 */

final class LmState
{
    public static bool $locked = true;
}

class LmItem extends Model
{
    protected $table = 'lm_items';

    protected $guarded = [];

    public $timestamps = false;
}

const LM_MODAL = ['title' => 'Pro feature', 'message' => 'Upgrade to unlock.', 'cta' => ['label' => 'Upgrade', 'url' => '/billing']];

/** A filter whose options and meta are the data a lock withholds. */
class LmFilter extends Filter
{
    public function __construct(string $name = 'Secret filter', private readonly bool $paid = true)
    {
        parent::__construct($name);
        $this->withMeta(['secret' => $paid ? 'FILTER-META-SECRET' : 'OPEN-FILTER-META']);

        if ($paid) {
            $this->lockedFor(fn (): bool => LmState::$locked)->lockModal(LM_MODAL);
        }
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('name', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }

    public function options(Request $request): array
    {
        return [$this->paid ? 'FILTER-OPTION-SECRET' : 'OPEN-FILTER-OPTION' => 'x'];
    }

    public function uriKey(): string
    {
        return $this->paid ? 'paid-filter' : 'open-filter';
    }
}

class LmTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Tool', uriKey: 'lm-tool');
        $this->withComponent('tool:lm-tool')->withMeta(['secret' => 'TOOL-META-SECRET', 'harmless' => 'x']);
        $this->lockedFor(fn (): bool => LmState::$locked)->lockModal(LM_MODAL);
    }
}

class LmOpenTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Open Tool', uriKey: 'lm-open-tool');
        $this->withComponent('tool:lm-open-tool')->withMeta(['secret' => 'OPEN-TOOL-META']);
    }
}

class LmDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Pro Lab', uriKey: 'pro-lab');
        $this->withMeta(['secret' => 'DASH-META-SECRET']);
        $this->lockedFor(fn (): bool => LmState::$locked)->lockModal(LM_MODAL);
    }
}

class LmHomeDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Home', uriKey: 'home');
    }

    public function filters(Request $request): array
    {
        return [new LmFilter('Open', paid: false), new LmFilter];
    }
}

class LmItemResource extends Resource
{
    public static function model(): string
    {
        return LmItem::class;
    }

    public static function uriKey(): string
    {
        return 'lm-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function filters(Request $request): array
    {
        return [new LmFilter('Open', paid: false), new LmFilter];
    }
}

const LM_BASE = '/martis/api';

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    LmState::$locked = true;

    Schema::dropIfExists('lm_items');
    Schema::create('lm_items', function ($table) {
        $table->id();
        $table->string('name');
    });

    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(LmItemResource::class);
    Martis::tools([new LmTool, new LmOpenTool]);
    Martis::dashboards([new LmDashboard, new LmHomeDashboard]);
    app(MartisCache::class)->clear('dashboards');
    app(MartisCache::class)->clear('schema');
    Cache::flush();
});

afterEach(function () {
    Martis::tools([]);
    Martis::dashboards([]);
    app(MartisCache::class)->clear('dashboards');
    app(MartisCache::class)->clear('schema');
    Schema::dropIfExists('lm_items');
});

it('withholds the meta of a locked tool from its page and from the tool list', function () {
    $page = $this->getJson(LM_BASE.'/tools/lm-tool')->assertOk()->assertJsonPath('locked', true);
    expect($page->getContent())->not->toContain('TOOL-META-SECRET')
        ->and($page->json('tool.meta'))->toBe([]);

    $list = $this->getJson(LM_BASE.'/tools')->assertOk();
    expect($list->getContent())->not->toContain('TOOL-META-SECRET');
    $row = collect($list->json())->firstWhere('uriKey', 'lm-tool');
    expect($row['meta'])->toBe([])->and($row['lock']['modal']['title'])->toBe('Pro feature');
});

it('serves the meta of a tool that is not locked, and of a locked one once the plan unlocks it', function () {
    expect(collect($this->getJson(LM_BASE.'/tools')->json())->firstWhere('uriKey', 'lm-open-tool')['meta'])
        ->toBe(['secret' => 'OPEN-TOOL-META']);

    LmState::$locked = false;
    $page = $this->getJson(LM_BASE.'/tools/lm-tool')->assertOk();
    expect($page->json('meta.secret'))->toBe('TOOL-META-SECRET');
});

it('withholds the meta of a locked dashboard from its page and from the dashboard list', function () {
    $page = $this->getJson(LM_BASE.'/dashboards/pro-lab')->assertOk()->assertJsonPath('data.locked', true);
    expect($page->getContent())->not->toContain('DASH-META-SECRET')
        ->and($page->json('data.dashboard.meta'))->toBe([]);

    $list = $this->getJson(LM_BASE.'/dashboards')->assertOk();
    expect($list->getContent())->not->toContain('DASH-META-SECRET');
});

it('lets the dashboard list follow the lock state of its dashboards at once (cache key in step)', function () {
    $locked = $this->getJson(LM_BASE.'/dashboards')->assertOk();
    expect($locked->getContent())->not->toContain('DASH-META-SECRET');

    // A plan upgrade lands on the next request, not when the cached list expires.
    LmState::$locked = false;
    $open = $this->getJson(LM_BASE.'/dashboards')->assertOk();
    expect($open->getContent())->toContain('DASH-META-SECRET');

    LmState::$locked = true;
    expect($this->getJson(LM_BASE.'/dashboards')->getContent())->not->toContain('DASH-META-SECRET');
});

it('withholds the options and meta of a locked filter on the resource schema, and follows the lock state', function () {
    $schema = fn () => $this->getJson(LM_BASE.'/resources/lm-items/schema')->assertOk();

    $locked = $schema();
    expect($locked->getContent())->not->toContain('FILTER-OPTION-SECRET')->not->toContain('FILTER-META-SECRET');
    $rows = collect($locked->json('data.filters') ?? $locked->json('filters'));
    expect($rows->firstWhere('uriKey', 'paid-filter')['options'])->toBe([])
        ->and($rows->firstWhere('uriKey', 'paid-filter')['lock']['modal']['title'])->toBe('Pro feature');

    // The control: the open filter keeps its options and meta.
    expect($rows->firstWhere('uriKey', 'open-filter')['options'][0]['label'])->toBe('OPEN-FILTER-OPTION')
        ->and($rows->firstWhere('uriKey', 'open-filter')['meta'])->toBe(['secret' => 'OPEN-FILTER-META']);

    // Unlocked: the cached payload does not outlive the lock.
    LmState::$locked = false;
    expect($schema()->getContent())->toContain('FILTER-META-SECRET');
    $unlocked = collect($schema()->json('data.filters') ?? $schema()->json('filters'));
    expect($unlocked->firstWhere('uriKey', 'paid-filter')['options'][0]['label'])->toBe('FILTER-OPTION-SECRET');

    LmState::$locked = true;
    expect($schema()->getContent())->not->toContain('FILTER-OPTION-SECRET');
    $again = collect($schema()->json('data.filters') ?? $schema()->json('filters'));
    expect($again->firstWhere('uriKey', 'paid-filter')['options'])->toBe([]);
});

it('withholds the options and meta of a locked dashboard filter, and follows the lock state', function () {
    $filters = fn () => collect($this->getJson(LM_BASE.'/dashboards/home')->assertOk()->json('data.filters'));

    $locked = $filters();
    expect($locked->firstWhere('uriKey', 'paid-filter')['options'])->toBe([])
        ->and($locked->firstWhere('uriKey', 'paid-filter')['meta'])->toBe([])
        ->and($locked->firstWhere('uriKey', 'open-filter')['options'][0]['label'])->toBe('OPEN-FILTER-OPTION');

    LmState::$locked = false;
    expect($filters()->firstWhere('uriKey', 'paid-filter')['options'][0]['label'])->toBe('FILTER-OPTION-SECRET');
});

it('does not resolve the options of a locked filter at all', function () {
    $filter = new class extends LmFilter
    {
        public static bool $resolved = false;

        public function options(Request $request): array
        {
            self::$resolved = true;

            return ['x' => 'y'];
        }
    };

    $filter->resolveForSchema(request());

    expect($filter::$resolved)->toBeFalse()
        ->and($filter->toArray()['options'])->toBe([]);
});
