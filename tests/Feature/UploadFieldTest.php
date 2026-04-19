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
// Test fixtures
// ---------------------------------------------------------------------------

class UploadTestModel extends Model
{
    protected $table = 'martis_test_uploads';

    protected $fillable = ['attachment', 'featured_image'];
}

class UploadTestResource extends Resource
{
    public static function model(): string
    {
        return UploadTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            File::make('attachment')
                ->disk('fake_disk')
                ->storagePath('test-uploads')
                ->maxSize(10240)
                ->acceptedTypes(['pdf', 'txt'])
                ->nullable(),

            Image::make('featured_image')
                ->disk('fake_disk')
                ->storagePath('test-images')
                ->thumbnail(100, 100)
                ->nullable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Storage::fake('fake_disk');

    Schema::dropIfExists('martis_test_uploads');
    Schema::create('martis_test_uploads', function ($table) {
        $table->id();
        $table->string('attachment')->nullable();
        $table->string('featured_image')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(UploadTestResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_uploads');
});

// ---------------------------------------------------------------------------
// Store — file upload via POST
// ---------------------------------------------------------------------------

it('POST store with file field stores file and saves path', function () {
    $file = UploadedFile::fake()->create('document.pdf', 512, 'application/pdf');

    // Use call() with explicit files array for correct multipart handling
    $response = $this->call(
        'POST',
        '/martis/api/resources/upload-test-models',
        [],
        [],
        ['attachment' => $file],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['attachment'])->not->toBeNull()
        ->and($data['attachment']['name'])->toEndWith('.pdf')
        ->and($data['attachment'])->toHaveKey('url')
        ->and($data['attachment'])->toHaveKey('path');

    Storage::disk('fake_disk')->assertExists($data['attachment']['path']);
});

it('POST store with image field stores image and generates thumbnail', function () {
    $image = UploadedFile::fake()->image('photo.jpg', 400, 300);

    $response = $this->call(
        'POST',
        '/martis/api/resources/upload-test-models',
        [],
        [],
        ['featured_image' => $image],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['featured_image'])->not->toBeNull()
        ->and($data['featured_image'])->toHaveKey('thumbnailUrl');

    $path = $data['featured_image']['path'];
    Storage::disk('fake_disk')->assertExists($path);

    $dir = dirname($path);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $prefix = ($dir === '.' || $dir === '') ? '' : $dir.'/';
    $thumbPath = $prefix.$filename.'_thumb.'.$ext;

    Storage::disk('fake_disk')->assertExists($thumbPath);
});

it('POST store without file field creates record with null attributes', function () {
    $response = $this->postJson('/martis/api/resources/upload-test-models', []);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['attachment'])->toBeNull()
        ->and($data['featured_image'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill — Storage-level tests via Feature context
// ---------------------------------------------------------------------------

it('File fill stores uploaded file and sets model attribute', function () {
    Storage::fake('fake_disk');

    $model = new UploadTestModel;
    $field = File::make('attachment')->disk('fake_disk')->storagePath('uploads');
    $upload = UploadedFile::fake()->create('doc.pdf', 512, 'application/pdf');

    $field->fill($model, $upload);

    $path = $model->getAttribute('attachment');
    expect($path)->not->toBeNull();
    Storage::disk('fake_disk')->assertExists($path);
});

it('File fill with null clears attribute and deletes file', function () {
    Storage::fake('fake_disk');

    $upload = UploadedFile::fake()->create('old.pdf', 100);
    $path = $upload->store('uploads', 'fake_disk');

    $model = new UploadTestModel(['attachment' => $path]);
    $field = File::make('attachment')->disk('fake_disk');

    $field->fill($model, null);

    expect($model->getAttribute('attachment'))->toBeNull();
    Storage::disk('fake_disk')->assertMissing($path);
});

it('File fill does nothing when readonly', function () {
    Storage::fake('fake_disk');

    $model = new UploadTestModel(['attachment' => 'uploads/original.pdf']);
    $field = File::make('attachment')->disk('fake_disk')->readonly();
    $upload = UploadedFile::fake()->create('new.pdf', 100);

    $field->fill($model, $upload);

    expect($model->getAttribute('attachment'))->toBe('uploads/original.pdf');
});

it('File resolve returns array with path, url, name', function () {
    Storage::fake('fake_disk');

    $model = new UploadTestModel(['attachment' => 'uploads/report.pdf']);
    $field = File::make('attachment')->disk('fake_disk');

    $result = $field->resolve($model);

    expect($result)->toBeArray()
        ->and($result['path'])->toBe('uploads/report.pdf')
        ->and($result['name'])->toBe('report.pdf')
        ->and($result['url'])->toContain('report.pdf');
});

it('File resolve returns null when attribute is null', function () {
    $model = new UploadTestModel(['attachment' => null]);
    $field = File::make('attachment')->disk('fake_disk');

    expect($field->resolve($model))->toBeNull();
});

it('File deleteStoredFile removes file from disk', function () {
    Storage::fake('fake_disk');

    $upload = UploadedFile::fake()->create('file.pdf', 100);
    $path = $upload->store('uploads', 'fake_disk');

    Storage::disk('fake_disk')->assertExists($path);

    $model = new UploadTestModel(['attachment' => $path]);
    $field = File::make('attachment')->disk('fake_disk');

    $field->deleteStoredFile($model);

    Storage::disk('fake_disk')->assertMissing($path);
});

it('Image fill generates thumbnail when configured', function () {
    Storage::fake('fake_disk');

    $model = new UploadTestModel;
    $field = Image::make('featured_image')->disk('fake_disk')->storagePath('test-images')->thumbnail(150, 150);
    $upload = UploadedFile::fake()->image('photo.jpg', 400, 300);

    $field->fill($model, $upload);

    $path = $model->getAttribute('featured_image');
    $dir = dirname($path);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $prefix = ($dir === '.' || $dir === '') ? '' : $dir.'/';
    $thumbPath = $prefix.$filename.'_thumb.'.$ext;

    Storage::disk('fake_disk')->assertExists($thumbPath);
});

it('Image deleteStoredFile removes image and thumbnail', function () {
    Storage::fake('fake_disk');

    $model = new UploadTestModel;
    $field = Image::make('featured_image')->disk('fake_disk')->storagePath('images')->thumbnail(100, 100);
    $upload = UploadedFile::fake()->image('photo.jpg', 200, 200);

    $field->fill($model, $upload);

    $path = $model->getAttribute('featured_image');
    $dir = dirname($path);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $prefix = ($dir === '.' || $dir === '') ? '' : $dir.'/';
    $thumbPath = $prefix.$filename.'_thumb.'.$ext;

    Storage::disk('fake_disk')->assertExists($path);
    Storage::disk('fake_disk')->assertExists($thumbPath);

    $field->deleteStoredFile($model);

    Storage::disk('fake_disk')->assertMissing($path);
    Storage::disk('fake_disk')->assertMissing($thumbPath);
});

// ---------------------------------------------------------------------------
// Update — replace file
// ---------------------------------------------------------------------------

it('PUT update with new file replaces old file', function () {
    // Create record with initial file
    $first = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-test-models',
        [],
        [],
        ['attachment' => $first],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $createResponse->assertStatus(201);

    $id = $createResponse->json('data.id');
    $oldPath = $createResponse->json('data.attachment.path');

    Storage::disk('fake_disk')->assertExists($oldPath);

    // Update with new file
    $second = UploadedFile::fake()->create('second.pdf', 200, 'application/pdf');
    $updateResponse = $this->call(
        'PUT',
        "/martis/api/resources/upload-test-models/{$id}",
        [],
        [],
        ['attachment' => $second],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $updateResponse->assertStatus(200);

    $newPath = $updateResponse->json('data.attachment.path');
    expect($newPath)->not->toBeNull()
        ->and($newPath)->not->toBe($oldPath);

    Storage::disk('fake_disk')->assertMissing($oldPath);
    Storage::disk('fake_disk')->assertExists($newPath);
});

it('PUT update without file field keeps existing file', function () {
    $file = UploadedFile::fake()->create('keep.pdf', 100, 'application/pdf');
    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-test-models',
        [],
        [],
        ['attachment' => $file],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $id = $createResponse->json('data.id');
    $existingPath = $createResponse->json('data.attachment.path');

    $updateResponse = $this->putJson("/martis/api/resources/upload-test-models/{$id}", []);
    $updateResponse->assertStatus(200);

    Storage::disk('fake_disk')->assertExists($existingPath);
    expect($updateResponse->json('data.attachment.path'))->toBe($existingPath);
});

// ---------------------------------------------------------------------------
// Destroy — deletes stored files
// ---------------------------------------------------------------------------

it('DELETE destroy removes stored files from disk', function () {
    $file = UploadedFile::fake()->create('to-delete.pdf', 50, 'application/pdf');
    $image = UploadedFile::fake()->image('to-delete.jpg', 100, 100);

    $createResponse = $this->call(
        'POST',
        '/martis/api/resources/upload-test-models',
        [],
        [],
        ['attachment' => $file, 'featured_image' => $image],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $createResponse->assertStatus(201);

    $id = $createResponse->json('data.id');
    $filePath = $createResponse->json('data.attachment.path');
    $imagePath = $createResponse->json('data.featured_image.path');

    Storage::disk('fake_disk')->assertExists($filePath);
    Storage::disk('fake_disk')->assertExists($imagePath);

    $deleteResponse = $this->deleteJson("/martis/api/resources/upload-test-models/{$id}");
    $deleteResponse->assertStatus(200);

    Storage::disk('fake_disk')->assertMissing($filePath);
    Storage::disk('fake_disk')->assertMissing($imagePath);
});

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

it('GET schema exposes file and image field metadata', function () {
    $response = $this->getJson('/martis/api/resources/upload-test-models/schema');

    $response->assertStatus(200);

    $fields = $response->json('data.fields');
    $fileField = collect($fields)->firstWhere('attribute', 'attachment');
    $imageField = collect($fields)->firstWhere('attribute', 'featured_image');

    expect($fileField['type'])->toBe('file')
        ->and($fileField['maxSize'])->toBe(10240)
        ->and($fileField['acceptedTypes'])->toBe(['pdf', 'txt'])
        ->and($fileField['disk'])->toBe('fake_disk');

    expect($imageField['type'])->toBe('image')
        ->and($imageField['thumbnailWidth'])->toBe(100)
        ->and($imageField['thumbnailHeight'])->toBe(100);
});
