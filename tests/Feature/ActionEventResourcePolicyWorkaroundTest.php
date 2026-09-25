<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ---------------------------------------------------------------------------
// On 1.x the built-in audit log resource (ActionEventResource) ships no
// policy, so every panel user can read it (Resource authorization is
// permissive without a policy). The v1.39.3 upgrade notes tell an app to
// register a policy for Martis\Models\ActionEvent; this pins that the
// documented snippet closes the index, the detail and the navigation.
// ---------------------------------------------------------------------------

class AERWUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/** The snippet of docs/upgrading.md, with an email check as the ability. */
class AERWActionEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->getAttribute('email') === 'auditor@example.com';
    }

    public function view(User $user, ActionEvent $event): bool
    {
        return $this->viewAny($user);
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

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields')->nullable();
        $t->string('status')->default('finished');
        $t->text('exception')->nullable();
        $t->text('original')->nullable();
        $t->text('changes')->nullable();
        $t->timestamps();
    });

    $this->auditor = AERWUser::query()->create(['name' => 'Auditor', 'email' => 'auditor@example.com', 'password' => bcrypt('secret')]);
    $this->agent = AERWUser::query()->create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => bcrypt('secret')]);
    $this->eventId = DB::table('martis_action_events')->insertGetId([
        'user_id' => $this->auditor->getKey(),
        'name' => 'item.updated',
        'status' => 'finished',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ActionEventResource::class);
    Resource::flushPolicyCache();
});

afterEach(function () {
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
});

it('shows the audit log to every panel user without a policy (the 1.x default)', function () {
    $this->actingAs($this->agent, 'web');

    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey())->assertOk();
    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey().'/'.$this->eventId)->assertOk();
});

it('closes the audit log to a user the documented ActionEvent policy denies, and keeps it for one it allows', function () {
    Gate::policy(ActionEvent::class, AERWActionEventPolicy::class);

    $this->actingAs($this->agent, 'web');
    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey())->assertForbidden();
    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey().'/'.$this->eventId)->assertForbidden();

    $this->actingAs($this->auditor, 'web');
    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey())->assertOk();
    $this->getJson('/martis/api/resources/'.ActionEventResource::uriKey().'/'.$this->eventId)->assertOk();
});
