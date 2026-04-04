<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Martis\Http\Middleware\MartisAuthenticate;

it('uploads an attachment and returns URL', function () {
    Storage::fake('public');

    $this->withoutMiddleware(MartisAuthenticate::class);

    $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

    $response = $this->postJson(config('martis.path', 'martis').'/api/attachments/upload', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['url', 'href']);
});

it('rejects attachment upload without file', function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    $response = $this->postJson(config('martis.path', 'martis').'/api/attachments/upload', []);

    $response->assertStatus(422);
});
