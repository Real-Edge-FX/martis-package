<?php

declare(strict_types=1);

use Martis\Support\BootstrapProvidersPatcher;

/*
 * bootstrap/providers.php belongs to the host app. The provider goes first
 * in the array, indented by four spaces, in the file's own style (an import
 * and a short entry when the file imports its providers), and every other
 * line stays as it was.
 */

beforeEach(function () {
    $this->patcher = new BootstrapProvidersPatcher;
    $this->provider = 'App\\Providers\\MartisServiceProvider';
});

it('adds a fully qualified entry first and keeps the next entry indented (Laravel 11 and 12.0 skeleton)', function () {
    $skeleton = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($skeleton, $this->provider))->toBe(
        "<?php\n\nreturn [\n    App\\Providers\\MartisServiceProvider::class,\n    App\\Providers\\AppServiceProvider::class,\n];\n"
    );
});

it('adds an import and a short entry when the file imports its providers (Laravel 12.x and 13 skeleton)', function () {
    $skeleton = "<?php\n\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($skeleton, $this->provider))->toBe(
        "<?php\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\MartisServiceProvider;\n\nreturn [\n    MartisServiceProvider::class,\n    AppServiceProvider::class,\n];\n"
    );
});

it('fills an empty array', function () {
    expect($this->patcher->add("<?php\n\nreturn [];\n", $this->provider))->toBe(
        "<?php\n\nreturn [\n    App\\Providers\\MartisServiceProvider::class,\n];\n"
    );
});

it('keeps CRLF line endings', function () {
    $skeleton = "<?php\r\n\r\nreturn [\r\n    App\\Providers\\AppServiceProvider::class,\r\n];\r\n";

    $patched = $this->patcher->add($skeleton, $this->provider);

    expect($patched)->toBe(
        "<?php\r\n\r\nreturn [\r\n    App\\Providers\\MartisServiceProvider::class,\r\n    App\\Providers\\AppServiceProvider::class,\r\n];\r\n"
    )->and(preg_match("/(?<!\r)\n/", (string) $patched))->toBe(0);
});

it('slots the import alphabetically into the first import block without moving the others', function () {
    $file = "<?php\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\TelescopeServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n    TelescopeServiceProvider::class,\n];\n";

    expect($this->patcher->add($file, $this->provider))->toBe(
        "<?php\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\MartisServiceProvider;\nuse App\\Providers\\TelescopeServiceProvider;\n\nreturn [\n    MartisServiceProvider::class,\n    AppServiceProvider::class,\n    TelescopeServiceProvider::class,\n];\n"
    );
});

it('does not match a comment mentioning "return [" before the real array', function () {
    $skeleton = "<?php\n\n/* Providers: return [ ... ] below. */\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($skeleton, $this->provider))->toBe(
        "<?php\n\n/* Providers: return [ ... ] below. */\n\nreturn [\n    App\\Providers\\MartisServiceProvider::class,\n    App\\Providers\\AppServiceProvider::class,\n];\n"
    );
});

it('returns null when the file does not return an array literal', function () {
    expect($this->patcher->add("<?php\n\nreturn array(\n    App\\Providers\\AppServiceProvider::class,\n);\n", $this->provider))->toBeNull()
        ->and($this->patcher->add("<?php\n\n\$providers = [];\n\nreturn \$providers;\n", $this->provider))->toBeNull();
});

it('returns null rather than write the entry into a block comment', function () {
    $file = "<?php\n\n/*\nreturn [\n    App\\Providers\\OldServiceProvider::class,\n];\n*/\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($file, $this->provider))->toBeNull();
});

it('returns null rather than write the import into a block comment', function () {
    $file = "<?php\n\n/*\nuse App\\Providers\\TelescopeServiceProvider;\n*/\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($file, $this->provider))->toBeNull();
});

it('returns null rather than write the entry into a closure returning its own array', function () {
    $file = "<?php\n\n\$local = static function (): array {\n    return [\n        App\\Providers\\DebugServiceProvider::class,\n    ];\n};\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    ...\$local(),\n];\n";

    expect($this->patcher->add($file, $this->provider))->toBeNull();
});

it('still patches a file whose block comment holds no statement', function () {
    $file = "<?php\n\n/*\n * Providers the app registers.\n */\n\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n];\n";

    expect($this->patcher->add($file, $this->provider))->toBe(
        "<?php\n\n/*\n * Providers the app registers.\n */\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\MartisServiceProvider;\n\nreturn [\n    MartisServiceProvider::class,\n    AppServiceProvider::class,\n];\n"
    );
});
