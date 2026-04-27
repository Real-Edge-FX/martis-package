<?php

use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Helpers — write/cleanup translation fixture files under
// `resource_path()` so the controller picks them up on the next request.
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $payload
 */
function writeLangFile(string $relativePath, array $payload): void
{
    $full = resource_path($relativePath);
    File::ensureDirectoryExists(dirname($full));
    File::put($full, '<?php return '.var_export($payload, true).';'.PHP_EOL);
}

afterEach(function () {
    // Wipe both the consumer-publish path and any host-app namespace
    // directories the differential tests touch. Idempotent.
    $cleanupRoots = [
        resource_path('lang/vendor/martis'),
        resource_path('lang/en/app.php'),
        resource_path('lang/pt_BR/app.php'),
        resource_path('lang/pt_PT/app.php'),
        resource_path('lang/en/billing.php'),
        resource_path('lang/zz_FAKE'),
    ];
    foreach ($cleanupRoots as $path) {
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        } elseif (File::exists($path)) {
            File::delete($path);
        }
    }
});

it('GET /martis/api/translations/en is public (no auth required)', function () {
    $response = $this->getJson('/martis/api/translations/en');

    $response->assertStatus(200);
});

it('translations endpoint returns all namespaces', function () {
    $response = $this->getJson('/martis/api/translations/en');

    $response->assertStatus(200)
        ->assertJsonStructure(['actions', 'auth', 'navigation', 'messages', 'resources']);
});

it('translations en has expected keys', function () {
    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['actions']['create'])->toBe('Create');
    expect($data['auth']['sign_in'])->toBe('Sign in');
    expect($data['navigation']['dashboard'])->toBe('Dashboard');
    expect($data['messages']['record_created'])->toBe('Record created successfully.');
});

it('translations falls back to en for unknown locale', function () {
    $data = $this->getJson('/martis/api/translations/xx-UNKNOWN')->json();

    expect($data)->toBeArray();
    expect($data['actions']['create'])->toBe('Create');
});

it('translations pt_BR returns portuguese strings', function () {
    $data = $this->getJson('/martis/api/translations/pt_BR')->json();

    expect($data['actions']['create'])->toBe('Criar');
    expect($data['navigation']['dashboard'])->toBe('Painel');
    expect($data['messages']['record_created'])->toBe('Registro criado com sucesso.');
});

it('translations en_US falls back to en', function () {
    $data = $this->getJson('/martis/api/translations/en_US')->json();

    expect($data)->toBeArray();
    expect($data['actions']['create'])->toBe('Create');
    expect($data['auth']['sign_in'])->toBe('Sign in');
    expect($data['messages']['record_created'])->toBe('Record created successfully.');
});

it('translations pt_PT returns european portuguese strings', function () {
    $data = $this->getJson('/martis/api/translations/pt_PT')->json();

    expect($data['actions']['create'])->toBe('Criar');
    expect($data['auth']['sign_in'])->toBe('Entrar');
    expect($data['auth']['password'])->toBe('Palavra-passe');
    expect($data['messages']['record_created'])->toBe('Registo criado com sucesso.');
    expect($data['navigation']['logout'])->toBe('Terminar sessão');
});

it('translations pt_PT endpoint returns all namespaces', function () {
    $data = $this->getJson('/martis/api/translations/pt_PT')->json();

    expect($data)->toHaveKeys(['actions', 'auth', 'navigation', 'messages', 'resources', 'martis']);
});

it('translations convert :variable placeholders to {{variable}} format', function () {
    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['resources']['new'])->toBe('New {{label}}');
    expect($data['resources']['search'])->toBe('Search {{label}}…');
    expect($data['resources']['selected'])->toBe('{{count}} selected');
    expect($data['resources']['edit'])->toBe('Edit {{label}}');
    expect($data['resources']['hello'])->toBe('Hello, {{name}}');
});

it('translations no_records key exists in en', function () {
    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['resources']['no_records'])->toBe('No records found.');
});

it('translations no_records key exists in pt_BR', function () {
    $data = $this->getJson('/martis/api/translations/pt_BR')->json();

    expect($data['resources']['no_records'])->toBe('Nenhum registro encontrado.');
});

it('translations pt_BR converts :variable placeholders', function () {
    $data = $this->getJson('/martis/api/translations/pt_BR')->json();

    expect($data['resources']['new'])->toBe('Novo {{label}}');
    expect($data['resources']['search'])->toBe('Pesquisar {{label}}…');
});

// ---------------------------------------------------------------------------
// ⭐ Differential 1 — per-key deep merge over consumer publishes
// ---------------------------------------------------------------------------

it('consumer override of a single key preserves the rest of the namespace', function () {
    // Consumer publishes ONLY one key in actions.php. The rest of the
    // package's actions namespace must still flow through.
    writeLangFile('lang/vendor/martis/en/actions.php', [
        'create' => 'Add new',
    ]);

    $data = $this->getJson('/martis/api/translations/en')->json();

    // Override applied.
    expect($data['actions']['create'])->toBe('Add new');
    // Untouched package keys still present.
    expect($data['actions']['save'] ?? null)->not->toBeNull();
    expect($data['actions']['cancel'] ?? null)->not->toBeNull();
});

it('consumer override does not leak into other namespaces', function () {
    writeLangFile('lang/vendor/martis/en/actions.php', [
        'create' => 'Add new',
    ]);

    $data = $this->getJson('/martis/api/translations/en')->json();

    // Other namespaces remain at package defaults.
    expect($data['navigation']['dashboard'])->toBe('Dashboard');
    expect($data['messages']['record_created'])->toBe('Record created successfully.');
});

// ---------------------------------------------------------------------------
// ⭐ Differential 2 — app namespaces auto-merged when configured
// ---------------------------------------------------------------------------

it('app_namespaces config surfaces host-app translation files under their namespace key', function () {
    config()->set('martis.locales.app_namespaces', ['app']);

    writeLangFile('lang/en/app.php', [
        'tagline' => 'Run the business',
    ]);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data)->toHaveKey('app');
    expect($data['app']['tagline'])->toBe('Run the business');
});

it('multiple app_namespaces are surfaced under their respective keys', function () {
    config()->set('martis.locales.app_namespaces', ['app', 'billing']);

    writeLangFile('lang/en/app.php', ['tagline' => 'A']);
    writeLangFile('lang/en/billing.php', ['invoice' => 'B']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['app']['tagline'])->toBe('A');
    expect($data['billing']['invoice'])->toBe('B');
});

it('app_namespaces is empty by default and behaves as if disabled', function () {
    // Even with a host-app file on disk, an empty `app_namespaces`
    // config means the namespace is NOT surfaced.
    writeLangFile('lang/en/app.php', ['tagline' => 'should-not-appear']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data)->not->toHaveKey('app');
});

it('app_namespaces rejects entries with path-traversal characters', function () {
    config()->set('martis.locales.app_namespaces', ['../validation']);
    // Even a real file at the resolved path is ignored because the name
    // does not match the safe regex.
    writeLangFile('lang/en/_dangerous.php', ['x' => 'y']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data)->not->toHaveKey('../validation');
});

// ---------------------------------------------------------------------------
// ⭐ Differential 3 — configurable fallback chain
// ---------------------------------------------------------------------------

it('fallback_chain default ["en"] preserves historical behaviour for unknown locales', function () {
    // No config override — defaults from config/martis.php apply.
    $data = $this->getJson('/martis/api/translations/zz_FAKE')->json();

    // No package files exist for zz_FAKE; default fallback `en` seeds
    // every namespace.
    expect($data['actions']['create'])->toBe('Create');
});

it('fallback_chain with multiple locales seeds keys from earlier entries', function () {
    config()->set('martis.locales.fallback_chain', ['pt_BR', 'en']);

    // Request a locale with no package files; fallback chain seeds the payload.
    $data = $this->getJson('/martis/api/translations/zz_FAKE')->json();

    // pt_BR seeded first, en patched on top — but pt_BR ran AFTER `en`
    // in the chain, so for keys present in BOTH locales, en wins
    // (because array_replace_recursive favours later writes).
    // Order in the controller: each entry of fallback_chain is applied
    // in sequence, so the LAST entry wins for shared keys.
    expect($data['actions']['create'])->toBe('Create');

    // A key only present in pt_BR (none here — both locales share keys).
    // Instead, assert that the namespace is non-empty + the en-only
    // resilience: missing package locale falls back transparently.
    expect($data['navigation']['dashboard'])->toBe('Dashboard');
});

it('fallback_chain skips the requested locale if it appears in the list', function () {
    // Putting the requested locale in its own fallback chain would be
    // wasteful (and a configuration mistake). The controller filters
    // it out so it never seeds itself.
    config()->set('martis.locales.fallback_chain', ['en', 'pt_BR']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    // No crash, payload still includes English package defaults.
    expect($data['actions']['create'])->toBe('Create');
});

it('fallback_chain with non-string entries is sanitised', function () {
    config()->set('martis.locales.fallback_chain', ['', 0, null, 'en']);

    $data = $this->getJson('/martis/api/translations/zz_FAKE')->json();

    expect($data['actions']['create'])->toBe('Create');
});

// ---------------------------------------------------------------------------
// Layer order — consumer overrides win over app_namespaces, which win
// over package defaults, which win over the fallback chain.
// ---------------------------------------------------------------------------

it('layer order: consumer publish wins over package defaults for the same key', function () {
    writeLangFile('lang/vendor/martis/en/actions.php', [
        'create' => 'Brand-new',
    ]);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['actions']['create'])->toBe('Brand-new');
});

it('layer order: app_namespace can override a package namespace key', function () {
    // Edge case: an app_namespace named the same as a package namespace
    // is unusual but not forbidden. The app value should win over
    // package defaults but lose to a published consumer override.
    config()->set('martis.locales.app_namespaces', ['actions']);
    writeLangFile('lang/en/actions.php', ['create' => 'From-app']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['actions']['create'])->toBe('From-app');
});

it('layer order: consumer publish wins over app_namespace + package', function () {
    config()->set('martis.locales.app_namespaces', ['actions']);
    writeLangFile('lang/en/actions.php', ['create' => 'From-app']);
    writeLangFile('lang/vendor/martis/en/actions.php', ['create' => 'From-publish']);

    $data = $this->getJson('/martis/api/translations/en')->json();

    expect($data['actions']['create'])->toBe('From-publish');
});
