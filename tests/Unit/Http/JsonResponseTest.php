<?php

use Martis\Http\Resources\JsonResponse;

it('JsonResponse::make returns a JsonResponse instance', function () {
    $response = JsonResponse::make(['id' => 1]);
    expect($response)->toBeInstanceOf(JsonResponse::class);
});

it('JsonResponse::toArray has data, meta, and links keys', function () {
    $response = JsonResponse::make(['id' => 1, 'title' => 'Hello']);
    $array = $response->toArray();

    expect($array)->toHaveKey('data')
        ->and($array)->toHaveKey('meta')
        ->and($array)->toHaveKey('links');
});

it('JsonResponse::toArray preserves data', function () {
    $data = ['id' => 42, 'name' => 'Martis'];
    $response = JsonResponse::make($data);

    expect($response->toArray()['data'])->toBe($data);
});

it('JsonResponse::toArray defaults meta and links to empty arrays', function () {
    $response = JsonResponse::make(['id' => 1]);
    $array = $response->toArray();

    expect($array['meta'])->toBe([])
        ->and($array['links'])->toBe([]);
});

it('JsonResponse::toArray carries custom meta and links', function () {
    $meta = ['version' => '1.0'];
    $links = ['self' => 'https://example.com/api/posts/1'];

    $response = JsonResponse::make(['id' => 1], $meta, $links);
    $array = $response->toArray();

    expect($array['meta'])->toBe($meta)
        ->and($array['links'])->toBe($links);
});

it('JsonResponse::toResponse returns HTTP 200 by default', function () {
    $response = JsonResponse::make(['id' => 1])->toResponse();
    expect($response->getStatusCode())->toBe(200);
});

it('JsonResponse::toResponse accepts a custom status code', function () {
    $response = JsonResponse::make(['id' => 1])->toResponse(201);
    expect($response->getStatusCode())->toBe(201);
});
