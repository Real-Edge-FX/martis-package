<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Exceptions\AuthorizationException;
use Martis\Exceptions\UserFacingException;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A delete that fails inside a hook, an observer or a storage driver does
 * not hand its message to the client: it can hold a path, a bucket or a
 * class name. Only an exception thrown on purpose for the user
 * (UserFacingException and the typed Martis exceptions), or `app.debug`,
 * lets a message through.
 */

class DeleteErrorsModel extends Model
{
    protected $table = 'delete_errors_items';

    protected $fillable = ['title'];
}

class DeleteErrorsResource extends Resource
{
    /** What `beforeDelete()` throws, set by each test. */
    public static ?Throwable $throws = null;

    public static function model(): string
    {
        return DeleteErrorsModel::class;
    }

    public static function uriKey(): string
    {
        return 'delete-errors-items';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function beforeDelete(Model $model, Request $request): void
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    DeleteErrorsResource::$throws = null;

    Schema::dropIfExists('delete_errors_items');
    Schema::create('delete_errors_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DeleteErrorsResource::class);

    $this->record = DeleteErrorsModel::create(['title' => 'Keep me']);
});

afterEach(function () {
    Schema::dropIfExists('delete_errors_items');
});

it('answers a generic message when a hook fails with an internal exception', function () {
    config(['app.debug' => false]);
    Log::spy();
    DeleteErrorsResource::$throws = new RuntimeException('S3 error at /var/www/app/storage/private/bucket-secret-name');

    $response = $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}");

    $response->assertStatus(500)->assertJsonPath('message', __('martis::messages.error_delete'));
    expect($response->getContent())->not->toContain('S3 error')->not->toContain('bucket-secret-name')->not->toContain('/var/www');
    expect(DeleteErrorsModel::find($this->record->id))->not->toBeNull();

    // The operator keeps the detail.
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'Martis: error on delete'
            && str_contains((string) ($context['error'] ?? ''), 'bucket-secret-name'),
    );
});

it('answers a generic message for an exception without a message', function () {
    config(['app.debug' => false]);
    DeleteErrorsResource::$throws = new RuntimeException;

    $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}")
        ->assertStatus(500)
        ->assertJsonPath('message', __('martis::messages.error_delete'));
});

it('lets the raw message through only when app.debug is on', function () {
    config(['app.debug' => true]);
    DeleteErrorsResource::$throws = new RuntimeException('Observer failed: cache tag missing');

    $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}")
        ->assertStatus(500)
        ->assertJsonPath('message', 'Observer failed: cache tag missing');
});

it('shows the message of a user-facing exception with its status in production', function () {
    config(['app.debug' => false]);
    DeleteErrorsResource::$throws = new UserFacingException('This record is protected and cannot be deleted.');

    $response = $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}");

    $response->assertStatus(422)
        ->assertJsonPath('message', 'This record is protected and cannot be deleted.')
        ->assertJsonPath('code', 'user_facing');
    expect(DeleteErrorsModel::find($this->record->id))->not->toBeNull();
});

it('lets a user-facing exception choose its status', function () {
    config(['app.debug' => false]);
    DeleteErrorsResource::$throws = new UserFacingException('Locked by another user.', 409);

    $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Locked by another user.');
});

it('keeps the status and message of the typed authorization exception', function () {
    config(['app.debug' => false]);
    DeleteErrorsResource::$throws = AuthorizationException::forAction('delete', 'invoice');

    $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'You are not authorized to delete this invoice.');
});

it('does not turn a base exception whose message is internal into a user-facing one', function () {
    config(['app.debug' => false]);
    DeleteErrorsResource::$throws = new LogicException('internal invariant: pivot table x missing');

    $response = $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}");

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('internal invariant');
});

it('still deletes the record when nothing fails', function () {
    $this->deleteJson("/martis/api/resources/delete-errors-items/{$this->record->id}")->assertOk();

    expect(DeleteErrorsModel::find($this->record->id))->toBeNull();
});
