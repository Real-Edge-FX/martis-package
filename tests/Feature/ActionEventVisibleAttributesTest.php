<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionEventRedactor;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// `$visible` in the action event log (v2.3.0). Nova stores an event's diffs
// through Orchestra\Sidekick\Eloquent\model_state(), whose attributesToArray()
// on a fresh instance keeps only the class's `$visible` attributes when it
// declares them: the others never reach the log. `$hidden` attributes inside
// `$visible` stay masked as `******` (ActionEventHiddenValuesTest).
// ===========================================================================

class AEVUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class AEVPivot extends Pivot
{
    protected $table = 'aev_report_tag';

    protected $visible = ['priority', 'secret'];

    protected $hidden = ['secret'];
}

class AEVReport extends Model
{
    protected $table = 'aev_reports';

    protected $guarded = [];

    protected $visible = ['name', 'api_token'];

    protected $hidden = ['api_token'];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(AEVTag::class, 'aev_report_tag', 'report_id', 'tag_id')
            ->using(AEVPivot::class)
            ->withPivot(['priority', 'grade', 'secret']);
    }
}

class AEVTag extends Model
{
    protected $table = 'aev_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AEVRewrite extends Action
{
    public ?string $name = 'Rewrite';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $report) {
            $report->forceFill(['name' => 'Rewritten', 'api_token' => 'new-token', 'search_vector' => 'rewritten'])->save();
        }

        return ActionResponse::message('Rewritten.');
    }
}

class AEVQueuedRewrite extends AEVRewrite implements ShouldQueue
{
    public ?string $name = 'Queued Rewrite';
}

class AEVRegrade extends Action
{
    public ?string $name = 'Regrade';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        foreach ($models as $tag) {
            $tag->pivot->priority = 'high';
            $tag->pivot->grade = 'B';
            $tag->pivot->secret = 'new-secret';
            $tag->pivot->save();
        }

        return ActionResponse::message('Regraded.');
    }
}

class AEVReportResource extends Resource
{
    public static function model(): string
    {
        return AEVReport::class;
    }

    public static function uriKey(): string
    {
        return 'aev-reports';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags', AEVTagResource::class)
                ->fields(fn () => [Text::make('priority')->nullable(), Text::make('grade')->nullable(), Text::make('secret')->nullable()])
                ->actions(fn () => [AEVRegrade::make()]),
        ];
    }

    public function actions(Request $request): array
    {
        return [AEVRewrite::make(), AEVQueuedRewrite::make()];
    }
}

class AEVTagResource extends Resource
{
    public static function model(): string
    {
        return AEVTag::class;
    }

    public static function uriKey(): string
    {
        return 'aev-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    foreach (['aev_reports', 'aev_tags', 'aev_report_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('aev_reports', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('api_token')->nullable();
        $t->string('search_vector')->nullable();
    });
    Schema::create('aev_tags', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('aev_report_tag', function ($t) {
        $t->id();
        $t->unsignedBigInteger('report_id');
        $t->unsignedBigInteger('tag_id');
        $t->string('priority')->nullable();
        $t->string('grade')->nullable();
        $t->string('secret')->nullable();
    });
    // The shape of stubs/create_martis_action_events_table.php.stub.
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->index();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields');
        $t->string('status', 25)->default('running');
        $t->text('exception');
        $t->text('original');
        $t->text('changes');
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([AEVReportResource::class, AEVTagResource::class] as $class) {
        $registry->register($class);
    }
    Resource::flushPolicyCache();
    ActionEventRedactor::flush();

    $this->actingAs(AEVUser::query()->create(['name' => 'Operator', 'email' => 'aev-operator@example.com', 'password' => 'x']), 'web');

    $this->report = AEVReport::query()->create(['name' => 'Report', 'api_token' => 'old-token', 'search_vector' => 'report']);
    $this->tag = AEVTag::query()->create(['name' => 'Tag']);
    $this->report->tags()->attach($this->tag->id, ['priority' => 'low', 'grade' => 'A', 'secret' => 'old-secret']);
});

afterEach(function () {
    foreach (['aev_reports', 'aev_tags', 'aev_report_tag', 'martis_action_events'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
    ActionEventRedactor::flush();
});

/** The stored (raw) original / changes of the only event of an action. */
function aevStored(string $name): array
{
    $row = ActionEvent::query()->where('name', $name)->sole();

    return [$row->original, $row->changes];
}

it('logs only the visible attributes of a model that declares $visible, hidden ones masked', function (string $action, string $name) {
    $this->postJson("/martis/api/resources/aev-reports/actions/{$action}", ['resources' => [$this->report->id]])->assertOk();

    expect(AEVReport::query()->find($this->report->id)?->getAttribute('search_vector'))->toBe('rewritten');

    [$original, $changes] = aevStored($name);

    expect($original)->toBe(['name' => 'Report', 'api_token' => ActionEventRedactor::MASK])
        ->and($changes)->toBe(['name' => 'Rewritten', 'api_token' => ActionEventRedactor::MASK]);
})->with([
    'synchronous' => ['a-e-v-rewrite', 'Rewrite'],
    'queued' => ['a-e-v-queued-rewrite', 'Queued Rewrite'],
]);

it('logs only the visible columns of a pivot model that declares $visible', function () {
    $this->postJson("/martis/api/resources/aev-reports/{$this->report->id}/belongs-to-many/tags/actions/a-e-v-regrade", [
        'resources' => [$this->tag->id],
    ])->assertOk();

    [$original, $changes] = aevStored('Regrade');

    expect($original)->toBe(['priority' => 'low', 'secret' => ActionEventRedactor::MASK])
        ->and($changes)->toBe(['priority' => 'high', 'secret' => ActionEventRedactor::MASK]);
});
