<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Resource::documentTitle() (v2.18.0): the browser tab title of a record's
// detail. The detail response carries it as `_documentTitle` (null keeps the
// panel's "{singular label}: {title}"); the form contexts do not.
// ===========================================================================

class DTConversation extends Model
{
    protected $table = 'dt_conversations';

    protected $guarded = [];

    public $timestamps = false;
}

class DTConversationResource extends Resource
{
    public static function model(): string
    {
        return DTConversation::class;
    }

    public static function uriKey(): string
    {
        return 'dt-conversations';
    }

    public static function titleAttribute(): string
    {
        return 'subject';
    }

    public function documentTitle(): ?string
    {
        return $this->model?->getAttribute('subject') === 'plain'
            ? null
            : (string) $this->model?->getAttribute('subject');
    }

    public function fields(Request $request): array
    {
        return [Text::make('subject')];
    }
}

class DTNoteResource extends Resource
{
    public static function model(): string
    {
        return DTConversation::class;
    }

    public static function uriKey(): string
    {
        return 'dt-notes';
    }

    public function fields(Request $request): array
    {
        return [Text::make('subject')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('dt_conversations');
    Schema::create('dt_conversations', function ($table) {
        $table->id();
        $table->string('subject');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DTConversationResource::class);
    $registry->register(DTNoteResource::class);

    DTConversation::create(['id' => 1, 'subject' => 'Conversation of 7 Nov']);
    DTConversation::create(['id' => 2, 'subject' => 'plain']);
});

afterEach(function () {
    Schema::dropIfExists('dt_conversations');
});

it('answers the detail with the resource document title', function () {
    $this->getJson('/martis/api/resources/dt-conversations/1')
        ->assertOk()
        ->assertJsonPath('data._documentTitle', 'Conversation of 7 Nov')
        ->assertJsonPath('data._title', 'Conversation of 7 Nov');
});

it('answers null when the resource keeps the default title, per record or by default', function () {
    $this->getJson('/martis/api/resources/dt-conversations/2')
        ->assertOk()
        ->assertJsonPath('data._documentTitle', null);

    $response = $this->getJson('/martis/api/resources/dt-notes/1')->assertOk();
    expect($response->json('data'))->toHaveKey('_documentTitle')
        ->and($response->json('data._documentTitle'))->toBeNull();
});

it('leaves the document title out of the form contexts', function () {
    $response = $this->getJson('/martis/api/resources/dt-conversations/1?context=update')->assertOk();

    expect($response->json('data'))->not->toHaveKey('_documentTitle');
});
