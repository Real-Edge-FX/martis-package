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
