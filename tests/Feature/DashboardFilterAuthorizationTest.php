<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Enums\FilterType;
use Martis\Facades\Martis;
use Martis\Filters\Filter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

/*
 * A dashboard filter the user may not see (`canSee()`) is not applied to a
 * metric, whatever the `filters` query parameter names: the dashboard
 * payload and the resource index already drop it, so the gate must hold on
 * the metric endpoint too, or the card computes a breakdown reserved for
 * another role. The lock of a locked dashboard holds on the compute route
 * as it does on the dashboard route.
 */

class DashFilterAuthzRow extends Model
{
    protected $table = 'dash_filter_authz_rows';

    protected $fillable = ['status', 'owner'];
}

class DashFilterAuthzMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->count($request, DashFilterAuthzRow::class);
    }
}

class DashFilterAuthzStatusFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

class DashFilterAuthzOwnerFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('owner', $value);
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

class DashFilterAuthzDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Authz', uriKey: 'dash-filter-authz');
    }

    public function cards(Request $request): array
    {
        return [DashFilterAuthzMetric::make('Rows', 'rows')];
    }

    public function filters(Request $request): array
    {
        return [
            DashFilterAuthzStatusFilter::make('Status', 'status'),
            // A per-owner breakdown reserved for managers.
            DashFilterAuthzOwnerFilter::make('Owner', 'owner')->canSee(fn (Request $request): bool => false),
        ];
    }
}

class DashFilterAuthzLockedDashboard extends DashFilterAuthzDashboard
{
    public function __construct()
    {
        parent::__construct();
        $this->uriKey = 'dash-filter-authz-locked';
        $this->lockedFor(fn (): bool => true);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('dash_filter_authz_rows');
    Schema::create('dash_filter_authz_rows', function ($table) {
        $table->id();
        $table->string('status');
        $table->string('owner');
        $table->timestamps();
    });

    foreach ([['open', 'alice'], ['open', 'bob'], ['closed', 'alice'], ['closed', 'alice']] as [$status, $owner]) {
        DashFilterAuthzRow::create(['status' => $status, 'owner' => $owner]);
    }

    Martis::dashboards([new DashFilterAuthzDashboard, new DashFilterAuthzLockedDashboard]);
    app(MartisCache::class)->clear('dashboards');
    app(MartisCache::class)->clear('metrics');
});

afterEach(function () {
    Martis::dashboards([]);
    app(MartisCache::class)->clear('dashboards');
    app(MartisCache::class)->clear('metrics');
    Schema::dropIfExists('dash_filter_authz_rows');
});

function dashFilterAuthzValue($test, array $filters, string $dashboard = 'dash-filter-authz'): mixed
{
    $query = $filters === [] ? '' : '?filters='.urlencode((string) json_encode($filters));

    return $test->getJson("/martis/api/dashboards/{$dashboard}/cards/rows{$query}");
}

it('applies a dashboard filter the user may see to the metric', function () {
    $response = dashFilterAuthzValue($this, ['status' => 'open'])->assertOk();

    expect($response->json('data.result.value'))->toBe(2);
});

it('ignores a dashboard filter the user may not see', function () {
    $response = dashFilterAuthzValue($this, ['owner' => 'alice'])->assertOk();

    // Unfiltered: all four rows, not alice's three.
    expect($response->json('data.result.value'))->toBe(4);
});

it('applies the filters it may see and ignores the hidden one named beside them', function () {
    $response = dashFilterAuthzValue($this, ['owner' => 'bob', 'status' => 'closed'])->assertOk();

    // status=closed alone: two rows. owner=bob would leave none.
    expect($response->json('data.result.value'))->toBe(2);
});

it('does not compute the metric of a locked dashboard', function () {
    $response = dashFilterAuthzValue($this, [], 'dash-filter-authz-locked')->assertForbidden();

    expect($response->json('locked'))->toBeTrue();
    expect($response->json('lock'))->toBeArray();
    expect($response->getContent())->not->toContain('"value"');
});
