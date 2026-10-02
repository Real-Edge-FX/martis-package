<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The replicate prefill returns the values of a record's create form, so it
 * needs the view ability of that record, as the detail page does. A policy
 * that lets a user replicate (or update) a record it may not view must not
 * open the values through this route.
 */

class ReplicateViewModel extends Model
{
    protected $table = 'replicate_view_items';

    protected $fillable = ['title', 'secret'];
}

class ReplicateViewPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    /** A record whose title starts with "Hidden" is not viewable. */
    public function view($user, $model): bool
    {
        return ! str_starts_with((string) $model->title, 'Hidden');
    }

    public function replicate($user, $model): bool
    {
        return true;
    }

    public function create($user): bool
    {
        return true;
    }
}

class ReplicateViewResource extends Resource
{
    public static ?string $policy = ReplicateViewPolicy::class;

    public static function model(): string
    {
        return ReplicateViewModel::class;
    }

    public static function uriKey(): string
    {
        return 'replicate-view-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), Text::make('secret')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('replicate_view_items');
    Schema::create('replicate_view_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('secret')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ReplicateViewResource::class);

    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test', 'email' => 'user@test.local']));
});

afterEach(function () {
    Schema::dropIfExists('replicate_view_items');
    Resource::flushPolicyCache();
});

it('refuses the replicate prefill of a record the user may not view, even when replicate is allowed', function () {
    $hidden = ReplicateViewModel::create(['title' => 'Hidden record', 'secret' => 'classified-value']);

    $this->getJson("/martis/api/resources/replicate-view-items/{$hidden->id}")->assertForbidden();

    $response = $this->getJson("/martis/api/resources/replicate-view-items/{$hidden->id}/replicate");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('classified-value')->not->toContain('Hidden record');
});

it('still prefills the replicate form of a record the user may view and replicate', function () {
    $visible = ReplicateViewModel::create(['title' => 'Visible record', 'secret' => 'shared-value']);

    $this->getJson("/martis/api/resources/replicate-view-items/{$visible->id}/replicate")
        ->assertOk()
        ->assertJsonPath('data.values.title', 'Visible record')
        ->assertJsonPath('data.values.secret', 'shared-value');
});
