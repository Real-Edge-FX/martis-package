<?php

use Martis\Http\Resources\JsonErrorResponse;

// -------------------------------------------------------------------------
// Validation (422)
// -------------------------------------------------------------------------

it('validation() returns a JsonErrorResponse instance', function () {
    $response = JsonErrorResponse::validation(['email' => ['The email is required.']]);
    expect($response)->toBeInstanceOf(JsonErrorResponse::class);
});

it('validation() has status 422', function () {
    $response = JsonErrorResponse::validation(['name' => ['The name is required.']]);
    expect($response->status())->toBe(422);
});

it('validation() message is "The given data was invalid."', function () {
    $response = JsonErrorResponse::validation(['email' => ['The email is required.']]);
    expect($response->toArray()['message'])->toBe('The given data was invalid.');
});

it('validation() errors contain field, message, and code', function () {
    $response = JsonErrorResponse::validation([
        'email' => ['The email is required.'],
    ]);
    $errors = $response->toArray()['errors'];

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toHaveKey('field')
        ->and($errors[0])->toHaveKey('message')
        ->and($errors[0])->toHaveKey('code')
        ->and($errors[0]['field'])->toBe('email')
        ->and($errors[0]['message'])->toBe('The email is required.')
        ->and($errors[0]['code'])->toBe('required');
});

it('validation() expands multiple messages per field', function () {
    $response = JsonErrorResponse::validation([
        'email' => ['The email is required.', 'The email must be a valid email address.'],
    ]);
    $errors = $response->toArray()['errors'];

    expect($errors)->toHaveCount(2)
        ->and($errors[0]['code'])->toBe('required')
        ->and($errors[1]['code'])->toBe('email');
});

it('validation() handles multiple fields', function () {
    $response = JsonErrorResponse::validation([
        'email' => ['The email is required.'],
        'password' => ['The password must be at least 8 characters.'],
    ]);
    $errors = $response->toArray()['errors'];

    expect($errors)->toHaveCount(2);
    $fields = array_column($errors, 'field');
    expect($fields)->toContain('email')
        ->and($fields)->toContain('password');
});

it('validation() infers unique code from unique message', function () {
    $response = JsonErrorResponse::validation([
        'email' => ['The email already exists.'],
    ]);
    expect($response->toArray()['errors'][0]['code'])->toBe('unique');
});

it('validation() toResponse returns HTTP 422', function () {
    $response = JsonErrorResponse::validation(['name' => ['Required.']])->toResponse();
    expect($response->getStatusCode())->toBe(422);
});

it('validation response body is valid JSON with expected structure', function () {
    $response = JsonErrorResponse::validation(['email' => ['The email is required.']]);
    $http = $response->toResponse();
    $body = json_decode($http->getContent(), true);

    expect($body)->toHaveKey('message')
        ->and($body)->toHaveKey('errors')
        ->and($body['errors'])->toHaveCount(1);
});

// -------------------------------------------------------------------------
// Not Found (404)
// -------------------------------------------------------------------------

it('notFound() has status 404', function () {
    expect(JsonErrorResponse::notFound()->status())->toBe(404);
});

it('notFound() uses default message', function () {
    expect(JsonErrorResponse::notFound()->toArray()['message'])->toBe('Resource not found.');
});

it('notFound() accepts custom message', function () {
    $response = JsonErrorResponse::notFound('Post not found.');
    expect($response->toArray()['message'])->toBe('Post not found.');
});

it('notFound() errors array is empty', function () {
    expect(JsonErrorResponse::notFound()->toArray()['errors'])->toBe([]);
});

it('notFound() toResponse returns HTTP 404', function () {
    expect(JsonErrorResponse::notFound()->toResponse()->getStatusCode())->toBe(404);
});

// -------------------------------------------------------------------------
// Server Error (500)
// -------------------------------------------------------------------------

it('serverError() has status 500', function () {
    expect(JsonErrorResponse::serverError()->status())->toBe(500);
});

it('serverError() uses default message without leaking details', function () {
    expect(JsonErrorResponse::serverError()->toArray()['message'])->toBe('An unexpected error occurred.');
});

it('serverError() errors array is empty', function () {
    expect(JsonErrorResponse::serverError()->toArray()['errors'])->toBe([]);
});

it('serverError() toResponse returns HTTP 500', function () {
    expect(JsonErrorResponse::serverError()->toResponse()->getStatusCode())->toBe(500);
});
