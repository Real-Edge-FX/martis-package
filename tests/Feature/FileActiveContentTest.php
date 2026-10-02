<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Martis\Fields\File;
use Martis\Fields\Image;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Rules\NoActiveContent;

// ---------------------------------------------------------------------------
// A File field refuses active content (HTML, SVG, XML, script files) unless
// the developer opts in (F013). The public disk is served from the
// application's own origin, so an uploaded HTML or SVG document runs script
// in it, with the session of whoever opens the link.
// ---------------------------------------------------------------------------

class ActiveContentModel extends Model
{
    protected $table = 'martis_test_active_content';

    protected $guarded = [];
}

class ActiveContentResource extends Resource
{
    public static function model(): string
    {
        return ActiveContentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            // No acceptedTypes(): the default the finding is about.
            File::make('attachment')->disk('fake_disk')->storagePath('plain')->nullable(),

            File::make('documents')->multiple()->disk('fake_disk')->storagePath('multi')->nullable(),

            File::make('named')->disk('fake_disk')->storagePath('named')->preserveOriginalName()->nullable(),

            File::make('vector')->disk('fake_disk')->storagePath('vector')->acceptedTypes(['svg', 'png'])->nullable(),

            File::make('anything')->disk('fake_disk')->storagePath('any')->allowActiveContent()->nullable(),

            Image::make('picture')->disk('fake_disk')->storagePath('pictures')->preserveOriginalName()->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Storage::fake('fake_disk');

    Schema::dropIfExists('martis_test_active_content');
    Schema::create('martis_test_active_content', function ($table) {
        $table->id();
        $table->string('attachment')->nullable();
        $table->json('documents')->nullable();
        $table->string('named')->nullable();
        $table->string('vector')->nullable();
        $table->string('anything')->nullable();
        $table->string('picture')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ActiveContentResource::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_active_content');
});

/**
 * An upload with real content on disk, as a browser sends it: the server
 * reads its MIME type from the bytes, whatever the name says.
 */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'martis-upload-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * @param  array<string, mixed>  $files
 * @param  array<string, mixed>  $parameters
 */
function postActiveContent(array $files, array $parameters = []): TestResponse
{
    return test()->call('POST', '/martis/api/resources/active-content-models', $parameters, [], $files, ['HTTP_ACCEPT' => 'application/json']);
}

/**
 * The fields a 422 names, in the order of Martis's error list.
 *
 * @return list<string>
 */
function activeContentErrorFields(TestResponse $response): array
{
    return array_values(array_unique(array_column($response->json('errors') ?? [], 'field')));
}

function storedActiveContentFiles(): array
{
    return Storage::disk('fake_disk')->allFiles();
}

const ACTIVE_HTML = '<!doctype html><html><body><script>fetch("/martis/api/me")</script></body></html>';
const ACTIVE_SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
const PLAIN_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

// ---- the default: refuse -------------------------------------------------

it('refuses an HTML document by content, whatever its name says', function (string $name) {
    $response = postActiveContent(['attachment' => realUpload($name, ACTIVE_HTML)]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['attachment']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
})->with(['report.html', 'report.pdf', 'report.txt', 'report']);

it('refuses an SVG document by content', function () {
    $response = postActiveContent(['attachment' => realUpload('logo.svg', ACTIVE_SVG)]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['attachment']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
});

it('refuses a script file by MIME type', function (string $name, string $mime) {
    $response = postActiveContent(['attachment' => UploadedFile::fake()->create($name, 1, $mime)]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['attachment']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
})->with([
    'html' => ['page.html', 'text/html'],
    'xhtml' => ['page.xhtml', 'application/xhtml+xml'],
    'svg' => ['logo.svg', 'image/svg+xml'],
    'xml' => ['data.xml', 'text/xml'],
    'application xml' => ['data.xml', 'application/xml'],
    'rss' => ['feed.rss', 'application/rss+xml'],
    'javascript' => ['app.js', 'text/javascript'],
    'x-javascript' => ['app.js', 'application/x-javascript'],
    'php' => ['shell.php', 'application/x-httpd-php'],
    'x-php' => ['shell.php', 'text/x-php'],
]);

it('accepts the files that are not active content', function (string $name, string $content) {
    $response = postActiveContent(['attachment' => realUpload($name, $content)]);

    $response->assertStatus(201);
    expect(storedActiveContentFiles())->toHaveCount(1);
})->with([
    'pdf' => ['report.pdf', PLAIN_PDF],
    'text' => ['notes.txt', "Just some notes.\n"],
    'csv' => ['table.csv', "a,b\n1,2\n"],
]);

it('refuses active content in every multiple upload and stores none', function () {
    $response = postActiveContent(['documents' => [
        realUpload('one.pdf', PLAIN_PDF),
        realUpload('two.html', ACTIVE_HTML),
    ]]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['documents.1']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
});

it('accepts a multiple upload with no active content', function () {
    $response = postActiveContent(['documents' => [
        realUpload('one.pdf', PLAIN_PDF),
        realUpload('two.txt', "notes\n"),
    ]]);

    $response->assertStatus(201);
    expect(storedActiveContentFiles())->toHaveCount(2);
});

// ---- the stored extension counts -----------------------------------------

it('refuses an upload kept under an active extension, whatever its content', function (string $name) {
    // A GIF header with a script after it: the content reads as an image, the
    // kept name makes the web server serve it as markup.
    $response = postActiveContent(['named' => realUpload($name, "GIF89a\n<script>alert(1)</script>")]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['named']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
})->with([
    'html' => 'pic.html',
    'uppercase' => 'pic.HTML',
    'shtml' => 'pic.shtml',
    'svg' => 'pic.svg',
    'php' => 'pic.php',
    'phtml' => 'pic.phtml',
    'phar' => 'pic.phar',
    'php5' => 'pic.php5',
    'trailing space' => 'pic.php ',
    'htaccess' => '.htaccess',
    'rss' => 'feed.rss',
    'atom' => 'feed.atom',
    'rdf' => 'data.rdf',
    'rdfs' => 'schema.rdfs',
    'owl' => 'onto.owl',
    'xsd' => 'schema.xsd',
    'dtd' => 'doc.dtd',
    'xbl' => 'binding.xbl',
    'xul' => 'window.xul',
    'mathml' => 'formula.mathml',
    'mml' => 'formula.mml',
    'wsdl' => 'service.wsdl',
    'wadl' => 'service.wadl',
    'xspf' => 'list.xspf',
    'xaml' => 'page.xaml',
    'smil' => 'show.smil',
    'swf' => 'movie.swf',
    'uppercase rss' => 'FEED.RSS',
]);

it('keeps a name whose active-looking word is not its extension', function (string $name) {
    // The nearest neighbours of the added extensions: the word is the base name, or part of a longer
    // extension, never an extension of its own.
    $response = postActiveContent(['named' => realUpload($name, PLAIN_PDF)]);

    $response->assertStatus(201);
    expect($response->json('data.named.name'))->toBe($name);
})->with(['rss.pdf', 'xsd.pdf', 'atom.pdf', 'feeds.pdf', 'swf-notes.pdf', 'report.rssi.pdf']);

it('keeps the name of a file that is not active content', function () {
    $response = postActiveContent(['named' => realUpload('Quarterly report.final.pdf', PLAIN_PDF)]);

    $response->assertStatus(201);
    expect($response->json('data.named.name'))->toBe('Quarterly report.final.pdf');
});

it('refuses an image kept under an active extension', function () {
    // A real 1x1 GIF: the `image` rule passes, the kept `.html` name would
    // make the server send it as markup.
    $gif = realUpload('pic.html', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

    $response = postActiveContent(['picture' => $gif]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['picture']);
    expect(ActiveContentModel::count())->toBe(0)
        ->and(storedActiveContentFiles())->toBe([]);
});

it('stores a double extension with the unique suffix between the segments, so no extension is left to run', function () {
    $response = postActiveContent(['named' => realUpload('pic.php.jpg', PLAIN_PDF)]);

    $response->assertStatus(201);
    expect(storedActiveContentFiles()[0])->not->toMatch('/\.php(\.|$)/');
});

it('still accepts an image', function () {
    $response = postActiveContent(['picture' => UploadedFile::fake()->image('photo.png', 20, 20)]);

    $response->assertStatus(201);
});

// ---- the opt-in ----------------------------------------------------------

it('accepts a type that acceptedTypes() lists, and only that type', function () {
    postActiveContent(['vector' => realUpload('logo.svg', ACTIVE_SVG)])->assertStatus(201);

    $response = postActiveContent(['vector' => realUpload('page.html', ACTIVE_HTML)]);

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['vector']);
});

it('accepts active content on a field that calls allowActiveContent()', function () {
    postActiveContent(['anything' => realUpload('page.html', ACTIVE_HTML)])->assertStatus(201);
    postActiveContent(['anything' => realUpload('logo.svg', ACTIVE_SVG)])->assertStatus(201);
});

// ---- update --------------------------------------------------------------

it('keeps the stored file when an update uploads active content', function () {
    $created = postActiveContent(['attachment' => realUpload('report.pdf', PLAIN_PDF)]);
    $created->assertStatus(201);
    $id = $created->json('data.id');
    $path = $created->json('data.attachment.path');

    $response = $this->call(
        'POST',
        "/martis/api/resources/active-content-models/{$id}",
        ['_method' => 'PUT'],
        [],
        ['attachment' => realUpload('page.html', ACTIVE_HTML)],
        ['HTTP_ACCEPT' => 'application/json'],
    );

    $response->assertStatus(422);
    expect(activeContentErrorFields($response))->toBe(['attachment']);
    Storage::disk('fake_disk')->assertExists($path);
    expect(ActiveContentModel::find($id)->attachment)->toBe($path)
        ->and(storedActiveContentFiles())->toBe([$path]);
});

// ---- the rule and the field, without the controller ------------------------

it('builds the rule into the field rules and the item rules, unless the field opts in', function () {
    // The rule reaches the validator as a closure bound to the rule object.
    $find = fn (array $rules) => collect($rules)->contains(
        fn ($rule) => $rule instanceof Closure && (new ReflectionFunction($rule))->getClosureThis() instanceof NoActiveContent,
    );

    expect($find(File::make('f')->buildRules()))->toBeTrue()
        ->and($find(File::make('f')->multiple()->buildItemRules()))->toBeTrue()
        ->and($find(Image::make('f')->buildRules()))->toBeTrue()
        ->and($find(File::make('f')->allowActiveContent()->buildRules()))->toBeFalse()
        ->and($find(File::make('f')->multiple()->allowActiveContent()->buildItemRules()))->toBeFalse()
        ->and(File::make('f')->allowsActiveContent())->toBeFalse()
        ->and(File::make('f')->allowActiveContent()->allowsActiveContent())->toBeTrue();
});

it('leaves a value that is not an upload to the file rule', function () {
    $validator = Validator::make(['f' => 'a string'], ['f' => File::make('f')->buildRules()]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('f'))->toBe('The f field must be a file.');
});

it('reads every segment of a stored name, the extension the field gives it included', function (string $name, bool $refused) {
    $upload = realUpload('x.bin', 'plain bytes');

    expect(NoActiveContent::refuses($upload, [], $name))->toBe($refused);
})->with([
    'double extension' => ['pic.php.jpg', true],
    'inner html' => ['page.html.zip', true],
    'upper case' => ['PAGE.HTML', true],
    'trailing space' => ['shell.php ', true],
    'hidden config' => ['.htaccess', true],
    'plain' => ['report.final.pdf', false],
    'no extension' => ['report', false],
    'suffix between' => ['pic.php_ab12cd.jpg', false],
]);

it('lets a listed type through, for the extension and the MIME type alike', function () {
    $svg = realUpload('logo.svg', ACTIVE_SVG);

    expect(NoActiveContent::refuses($svg, [], 'logo.svg'))->toBeTrue()
        ->and(NoActiveContent::refuses($svg, ['svg'], 'logo.svg'))->toBeFalse()
        ->and(NoActiveContent::refuses($svg, ['.SVG'], 'logo.svg'))->toBeFalse()
        ->and(NoActiveContent::refuses($svg, ['png'], 'logo.svg'))->toBeTrue();
});

it('translates the message', function () {
    foreach (['en', 'pt_PT', 'pt_BR'] as $locale) {
        app()->setLocale($locale);

        $validator = Validator::make(
            ['f' => UploadedFile::fake()->create('page.html', 1, 'text/html')],
            ['f' => File::make('f', 'Attachment')->buildRules()],
            [],
            ['f' => 'Attachment'],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('f'))->toContain('Attachment')
            ->and($validator->errors()->first('f'))->not->toContain('martis::');
    }
});

it('refuses active content at fill time too, before it deletes the stored file', function () {
    Storage::disk('fake_disk')->put('plain/old.pdf', PLAIN_PDF);
    $model = new ActiveContentModel(['attachment' => 'plain/old.pdf']);
    $field = File::make('attachment')->disk('fake_disk')->storagePath('plain');

    expect(fn () => $field->fill($model, realUpload('page.html', ACTIVE_HTML)))->toThrow(ValidationException::class);

    Storage::disk('fake_disk')->assertExists('plain/old.pdf');
    expect($model->attachment)->toBe('plain/old.pdf');
});

it('refuses active content at fill time in multiple mode before it touches the stored files', function () {
    Storage::disk('fake_disk')->put('multi/old.pdf', PLAIN_PDF);
    $model = new ActiveContentModel(['documents' => json_encode(['multi/old.pdf'])]);
    $field = File::make('documents')->multiple()->disk('fake_disk')->storagePath('multi');

    expect(fn () => $field->fill($model, ['files' => [realUpload('ok.pdf', PLAIN_PDF), realUpload('page.html', ACTIVE_HTML)], 'existing' => []]))
        ->toThrow(ValidationException::class);

    expect(storedActiveContentFiles())->toBe(['multi/old.pdf']);
});
