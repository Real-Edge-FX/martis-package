<?php

use Martis\Http\Resources\JsonPaginatedResponse;

/** @return array{current_page: int, from: int|null, last_page: int, per_page: int, to: int|null, total: int} */
function fakePaginationMeta(int $total = 75, int $perPage = 15, int $currentPage = 1): array
{
    $lastPage = (int) ceil($total / $perPage);
    $from = ($currentPage - 1) * $perPage + 1;
    $to = min($from + $perPage - 1, $total);

    return [
        'current_page' => $currentPage,
        'from' => $from,
        'last_page' => $lastPage,
        'per_page' => $perPage,
        'to' => $to,
        'total' => $total,
    ];
}

it('JsonPaginatedResponse::make returns correct instance', function () {
    $response = JsonPaginatedResponse::make([], fakePaginationMeta());
    expect($response)->toBeInstanceOf(JsonPaginatedResponse::class);
});

it('JsonPaginatedResponse::toArray has data, meta, and links keys', function () {
    $response = JsonPaginatedResponse::make([['id' => 1]], fakePaginationMeta());
    $array = $response->toArray();

    expect($array)->toHaveKey('data')
        ->and($array)->toHaveKey('meta')
        ->and($array)->toHaveKey('links');
});

it('meta block contains all required pagination fields', function () {
    $meta = fakePaginationMeta(total: 30, perPage: 10, currentPage: 2);
    $response = JsonPaginatedResponse::make([], $meta);
    $resultMeta = $response->toArray()['meta'];

    expect($resultMeta)->toHaveKey('total')
        ->and($resultMeta)->toHaveKey('per_page')
        ->and($resultMeta)->toHaveKey('current_page')
        ->and($resultMeta)->toHaveKey('last_page')
        ->and($resultMeta)->toHaveKey('from')
        ->and($resultMeta)->toHaveKey('to');
});

it('meta block values are correct', function () {
    $meta = fakePaginationMeta(total: 30, perPage: 10, currentPage: 2);
    $response = JsonPaginatedResponse::make([], $meta);
    $resultMeta = $response->toArray()['meta'];

    expect($resultMeta['total'])->toBe(30)
        ->and($resultMeta['per_page'])->toBe(10)
        ->and($resultMeta['current_page'])->toBe(2)
        ->and($resultMeta['last_page'])->toBe(3)
        ->and($resultMeta['from'])->toBe(11)
        ->and($resultMeta['to'])->toBe(20);
});

it('extra meta is merged into the meta block', function () {
    $meta = fakePaginationMeta();
    $extra = ['filtered' => true, 'sort' => 'created_at'];
    $response = JsonPaginatedResponse::make([], $meta, links: ['first' => null, 'last' => null, 'prev' => null, 'next' => null], extraMeta: $extra);
    $resultMeta = $response->toArray()['meta'];

    expect($resultMeta['filtered'])->toBeTrue()
        ->and($resultMeta['sort'])->toBe('created_at');
});

it('data is preserved in the response', function () {
    $items = [['id' => 1, 'title' => 'Post A'], ['id' => 2, 'title' => 'Post B']];
    $response = JsonPaginatedResponse::make($items, fakePaginationMeta());

    expect($response->toArray()['data'])->toBe($items);
});

it('JsonPaginatedResponse::toResponse returns HTTP 200', function () {
    $response = JsonPaginatedResponse::make([], fakePaginationMeta())->toResponse();
    expect($response->getStatusCode())->toBe(200);
});

it('empty collection has from and to null', function () {
    $meta = [
        'current_page' => 1,
        'from' => null,
        'last_page' => 1,
        'per_page' => 15,
        'to' => null,
        'total' => 0,
    ];
    $response = JsonPaginatedResponse::make([], $meta);
    $resultMeta = $response->toArray()['meta'];

    expect($resultMeta['from'])->toBeNull()
        ->and($resultMeta['to'])->toBeNull()
        ->and($resultMeta['total'])->toBe(0);
});
