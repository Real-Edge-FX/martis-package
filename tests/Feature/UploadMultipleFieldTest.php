<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\File;
use Martis\Fields\Image;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — multiple mode
// ---------------------------------------------------------------------------

class UploadMultipleTestModel extends Model
{
    protected $table = 'martis_test_upload_multi';

    protected $fillable = ['documents', 'gallery'];
}

class UploadMultipleTestResource extends Resource
{
    public static function model(): string
    {
        return UploadMultipleTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            File::make('documents')
                ->multiple()
                ->disk('fake_disk')
                ->storagePath('test-multi-docs')
                ->maxSize(10240)
                ->acceptedTypes(['pdf', 'txt'])
                ->nullable(),

            Image::make('gallery')
                ->multiple()
                ->disk('fake_disk')
                ->storagePath('test-multi-imgs')
                ->thumbnail(100, 100)
                ->nullable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'martis_playground',
            'username' => 'martis',
            'password' => 'martis_password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],
    ]);

    $this->withoutMiddleware(MartisAuthenticate::class);

    Storage::fake('fake_disk');

    Schema::dropIfExists('martis_test_upload_multi');
    Schema::create('martis_test_upload_multi', function ($table) {
        $table->id();
        $table->json('documents')->nullable();
        $table->json('gallery')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(UploadMultipleTestResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_upload_multi');
});

// ---------------------------------------------------------------------------
// Store — multiple file uploads via POST
// ---------------------------------------------------------------------------

it('POST store with multiple files stores all and saves JSON paths', function () {
    $file1 = UploadedFile::fake()->create('doc1.pdf', 100, 'application/pdf');
    $file2 = UploadedFile::fake()->create('doc2.pdf', 200, 'application/pdf');

    $response = $this->call(
        'POST',
        '/martis/api/resources/upload-multiple-test-models',
        [],
        [],
        ['documents' => [$file1, $file2]],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['documents'])->toBeArray()
        ->and($data['documents'])->toHaveCount(2);

    foreach ($data['documents'] as $doc) {
        expect($doc)->toHaveKeys(['path', 'url', 'name']);
        Storage::disk('fake_disk')->assertExists($doc['path']);
    }
});

it('POST store with multiple images stores all with thumbnails', function () {
    $img1 = UploadedFile::fake()->image('photo1.jpg', 400, 300);
    $img2 = UploadedFile::fake()->image('photo2.jpg', 500, 400);

    $response = $this->call(
        'POST',
        '/martis/api/resources/upload-multiple-test-models',
        [],
        [],
        ['gallery' => [$img1, $img2]],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['gallery'])->toBeArray()
        ->and($data['gallery'])->toHaveCount(2);

    foreach ($data['gallery'] as $img) {
        expect($img)->toHaveKeys(['path', 'url', 'name', 'thumbnailUrl']);
        Storage::disk('fake_disk')->assertExists($img['path']);
    }
});

it('POST store without multiple fields creates record with empty arrays', function () {
    $response = $this->postJson('/martis/api/resources/upload-multiple-test-models', []);

    $response->assertStatus(201);

    $data = $response->json('data');
    // When no files submitted, the field isn't touched so model default applies
    expect($data['documents'])->toBeArray()
        ->and($data['gallery'])->toBeArray();
});

// ---------------------------------------------------------------------------
// Update — add new files while keeping existing
// ---------------------------------------------------------------------------

it('PUT update adds new files and keeps existing via _keep param', function () {
    // Create with 1 file
    $file1 = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-multiple-test-models',
        [],
        [],
        ['documents' => [$file1]],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $createResponse->assertStatus(201);
    $id = $createResponse->json('data.id');
    $firstPath = $createResponse->json('data.documents.0.path');

    // Update: keep first + add second
    $file2 = UploadedFile::fake()->create('second.pdf', 200, 'application/pdf');
    $updateResponse = $this->call(
        'POST', // method spoofing for multipart
        "/martis/api/resources/upload-multiple-test-models/{$id}",
        ['_method' => 'PUT', 'documents_keep' => [$firstPath]],
        [],
        ['documents' => [$file2]],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $updateResponse->assertStatus(200);

    $data = $updateResponse->json('data');
    expect($data['documents'])->toHaveCount(2);

    Storage::disk('fake_disk')->assertExists($firstPath);
    Storage::disk('fake_disk')->assertExists($data['documents'][1]['path']);
});

it('PUT update removes files not in _keep', function () {
    $file1 = UploadedFile::fake()->create('keep.pdf', 100, 'application/pdf');
    $file2 = UploadedFile::fake()->create('remove.pdf', 100, 'application/pdf');

    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-multiple-test-models',
        [],
        [],
        ['documents' => [$file1, $file2]],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $id = $createResponse->json('data.id');
    $keepPath = $createResponse->json('data.documents.0.path');
    $removePath = $createResponse->json('data.documents.1.path');

    // Update: only keep first
    $updateResponse = $this->call(
        'POST',
        "/martis/api/resources/upload-multiple-test-models/{$id}",
        ['_method' => 'PUT', 'documents_keep' => [$keepPath]],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $updateResponse->assertStatus(200);

    $data = $updateResponse->json('data');
    expect($data['documents'])->toHaveCount(1)
        ->and($data['documents'][0]['path'])->toBe($keepPath);

    Storage::disk('fake_disk')->assertExists($keepPath);
    Storage::disk('fake_disk')->assertMissing($removePath);
});

// ---------------------------------------------------------------------------
// Destroy — deletes all multiple stored files
// ---------------------------------------------------------------------------

it('DELETE destroy removes all multiple stored files', function () {
    $file1 = UploadedFile::fake()->create('a.pdf', 50, 'application/pdf');
    $img1 = UploadedFile::fake()->image('b.jpg', 100, 100);

    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-multiple-test-models',
        [],
        [],
        ['documents' => [$file1], 'gallery' => [$img1]],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $createResponse->assertStatus(201);

    $id = $createResponse->json('data.id');
    $docPath = $createResponse->json('data.documents.0.path');
    $imgPath = $createResponse->json('data.gallery.0.path');

    Storage::disk('fake_disk')->assertExists($docPath);
    Storage::disk('fake_disk')->assertExists($imgPath);

    $this->deleteJson("/martis/api/resources/upload-multiple-test-models/{$id}")
        ->assertStatus(200);

    Storage::disk('fake_disk')->assertMissing($docPath);
    Storage::disk('fake_disk')->assertMissing($imgPath);
});

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

it('GET schema exposes multiple=true for multi fields', function () {
    $response = $this->getJson('/martis/api/resources/upload-multiple-test-models/schema');

    $response->assertStatus(200);

    $fields = $response->json('data.fields');
    $docField = collect($fields)->firstWhere('attribute', 'documents');
    $imgField = collect($fields)->firstWhere('attribute', 'gallery');

    expect($docField['multiple'])->toBeTrue()
        ->and($docField['type'])->toBe('file');

    expect($imgField['multiple'])->toBeTrue()
        ->and($imgField['type'])->toBe('image');
});

// ---------------------------------------------------------------------------
// Fill — direct field-level tests (require Storage)
// ---------------------------------------------------------------------------

it('File multiple fill stores multiple files as JSON array', function () {
    $model = new UploadMultipleTestModel;
    $field = File::make('documents')->multiple()->disk('fake_disk')->storagePath('docs');

    $file1 = UploadedFile::fake()->create('doc1.pdf', 100);
    $file2 = UploadedFile::fake()->create('doc2.pdf', 200);

    $field->fill($model, ['files' => [$file1, $file2], 'existing' => []]);

    $paths = json_decode($model->getAttribute('documents'), true);
    expect($paths)->toBeArray()->and($paths)->toHaveCount(2);

    foreach ($paths as $path) {
        Storage::disk('fake_disk')->assertExists($path);
    }
});

it('File multiple fill keeps existing and adds new', function () {
    Storage::disk('fake_disk')->put('docs/existing.pdf', 'content');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', json_encode(['docs/existing.pdf']));

    $field = File::make('documents')->multiple()->disk('fake_disk')->storagePath('docs');
    $newFile = UploadedFile::fake()->create('new.pdf', 100);

    $field->fill($model, ['files' => [$newFile], 'existing' => ['docs/existing.pdf']]);

    $paths = json_decode($model->getAttribute('documents'), true);
    expect($paths)->toHaveCount(2)->and($paths[0])->toBe('docs/existing.pdf');
    Storage::disk('fake_disk')->assertExists('docs/existing.pdf');
    Storage::disk('fake_disk')->assertExists($paths[1]);
});

it('File multiple fill removes unselected files', function () {
    Storage::disk('fake_disk')->put('docs/keep.pdf', 'keep');
    Storage::disk('fake_disk')->put('docs/remove.pdf', 'remove');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', json_encode(['docs/keep.pdf', 'docs/remove.pdf']));

    $field = File::make('documents')->multiple()->disk('fake_disk')->storagePath('docs');
    $field->fill($model, ['files' => [], 'existing' => ['docs/keep.pdf']]);

    $paths = json_decode($model->getAttribute('documents'), true);
    expect($paths)->toHaveCount(1)->and($paths[0])->toBe('docs/keep.pdf');
    Storage::disk('fake_disk')->assertExists('docs/keep.pdf');
    Storage::disk('fake_disk')->assertMissing('docs/remove.pdf');
});

it('File multiple fill clears all when null', function () {
    Storage::disk('fake_disk')->put('docs/a.pdf', 'content');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', json_encode(['docs/a.pdf']));

    $field = File::make('documents')->multiple()->disk('fake_disk')->storagePath('docs');
    $field->fill($model, null);

    $paths = json_decode($model->getAttribute('documents'), true);
    expect($paths)->toBe([]);
    Storage::disk('fake_disk')->assertMissing('docs/a.pdf');
});

it('File multiple resolve returns array of file objects', function () {
    Storage::disk('fake_disk')->put('docs/a.pdf', 'a');
    Storage::disk('fake_disk')->put('docs/b.pdf', 'b');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', json_encode(['docs/a.pdf', 'docs/b.pdf']));

    $field = File::make('documents')->multiple()->disk('fake_disk');
    $result = $field->resolve($model);

    expect($result)->toBeArray()->and($result)->toHaveCount(2)
        ->and($result[0])->toHaveKeys(['path', 'url', 'name'])
        ->and($result[0]['name'])->toBe('a.pdf')
        ->and($result[1]['name'])->toBe('b.pdf');
});

it('File multiple resolve returns empty array when null', function () {
    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', null);

    $field = File::make('documents')->multiple()->disk('fake_disk');
    expect($field->resolve($model))->toBe([]);
});

it('File multiple deleteStoredFile deletes all files', function () {
    Storage::disk('fake_disk')->put('docs/a.pdf', 'a');
    Storage::disk('fake_disk')->put('docs/b.pdf', 'b');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('documents', json_encode(['docs/a.pdf', 'docs/b.pdf']));

    $field = File::make('documents')->multiple()->disk('fake_disk');
    $field->deleteStoredFile($model);

    Storage::disk('fake_disk')->assertMissing('docs/a.pdf');
    Storage::disk('fake_disk')->assertMissing('docs/b.pdf');
});

it('Image multiple fill generates thumbnails for each image', function () {
    $model = new UploadMultipleTestModel;
    $field = Image::make('gallery')->multiple()->disk('fake_disk')->storagePath('imgs')->thumbnail(100, 100);

    $img1 = UploadedFile::fake()->image('photo1.jpg', 300, 200);
    $img2 = UploadedFile::fake()->image('photo2.jpg', 400, 300);

    $field->fill($model, ['files' => [$img1, $img2], 'existing' => []]);

    $paths = json_decode($model->getAttribute('gallery'), true);
    expect($paths)->toHaveCount(2);

    foreach ($paths as $path) {
        Storage::disk('fake_disk')->assertExists($path);
        $dir = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $prefix = ($dir === '.' || $dir === '') ? '' : $dir.'/';
        Storage::disk('fake_disk')->assertExists($prefix.$filename.'_thumb.'.$ext);
    }
});

it('Image multiple resolve includes thumbnailUrl', function () {
    Storage::disk('fake_disk')->put('imgs/photo.jpg', 'img');
    Storage::disk('fake_disk')->put('imgs/photo_thumb.jpg', 'thumb');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('gallery', json_encode(['imgs/photo.jpg']));

    $field = Image::make('gallery')->multiple()->disk('fake_disk')->thumbnail(100, 100);
    $result = $field->resolve($model);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toHaveKeys(['path', 'url', 'name', 'thumbnailUrl'])
        ->and($result[0]['thumbnailUrl'])->toContain('photo_thumb.jpg');
});

it('Image multiple deleteStoredFile removes images and thumbnails', function () {
    Storage::disk('fake_disk')->put('imgs/a.jpg', 'a');
    Storage::disk('fake_disk')->put('imgs/a_thumb.jpg', 'at');
    Storage::disk('fake_disk')->put('imgs/b.jpg', 'b');
    Storage::disk('fake_disk')->put('imgs/b_thumb.jpg', 'bt');

    $model = new UploadMultipleTestModel;
    $model->setAttribute('gallery', json_encode(['imgs/a.jpg', 'imgs/b.jpg']));

    $field = Image::make('gallery')->multiple()->disk('fake_disk')->thumbnail(100, 100);
    $field->deleteStoredFile($model);

    Storage::disk('fake_disk')->assertMissing('imgs/a.jpg');
    Storage::disk('fake_disk')->assertMissing('imgs/a_thumb.jpg');
    Storage::disk('fake_disk')->assertMissing('imgs/b.jpg');
    Storage::disk('fake_disk')->assertMissing('imgs/b_thumb.jpg');
});
