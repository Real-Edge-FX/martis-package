<?php

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

it('translations pt-BR returns portuguese strings', function () {
    $data = $this->getJson('/martis/api/translations/pt-BR')->json();

    expect($data['actions']['create'])->toBe('Criar');
    expect($data['navigation']['dashboard'])->toBe('Painel');
    expect($data['messages']['record_created'])->toBe('Registro criado com sucesso.');
});

it('translations en-US is alias for en', function () {
    $data = $this->getJson('/martis/api/translations/en-US')->json();

    expect($data)->toBeArray();
    expect($data['actions']['create'])->toBe('Create');
    expect($data['auth']['sign_in'])->toBe('Sign in');
    expect($data['messages']['record_created'])->toBe('Record created successfully.');
});

it('translations pt-PT returns european portuguese strings', function () {
    $data = $this->getJson('/martis/api/translations/pt-PT')->json();

    expect($data['actions']['create'])->toBe('Criar');
    expect($data['auth']['sign_in'])->toBe('Entrar');
    expect($data['auth']['password'])->toBe('Palavra-passe');
    expect($data['messages']['record_created'])->toBe('Registo criado com sucesso.');
    expect($data['navigation']['logout'])->toBe('Terminar sessão');
});

it('translations pt-PT endpoint returns all namespaces', function () {
    $data = $this->getJson('/martis/api/translations/pt-PT')->json();

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

it('translations no_records key exists in pt-BR', function () {
    $data = $this->getJson('/martis/api/translations/pt-BR')->json();

    expect($data['resources']['no_records'])->toBe('Nenhum registro encontrado.');
});

it('translations pt-BR converts :variable placeholders', function () {
    $data = $this->getJson('/martis/api/translations/pt-BR')->json();

    expect($data['resources']['new'])->toBe('Novo {{label}}');
    expect($data['resources']['search'])->toBe('Pesquisar {{label}}…');
});
