<?php

namespace Tests\Unit;

use Martis\Exceptions\AuthorizationException;
use Martis\Exceptions\MartisException;
use Martis\Exceptions\ResourceNotFoundException;
use Martis\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // MartisException
    // -------------------------------------------------------------------------

    public function test_martis_exception_has_default_values(): void
    {
        $e = new MartisException('Test error');

        $this->assertSame('Test error', $e->getMessage());
        $this->assertSame('martis_error', $e->errorCode());
        $this->assertSame([], $e->context());
        $this->assertSame(500, $e->httpStatus());
    }

    public function test_martis_exception_to_array(): void
    {
        $e = new MartisException('Oops', 'custom_code');

        $this->assertSame([
            'code' => 'custom_code',
            'message' => 'Oops',
        ], $e->toArray());
    }

    public function test_martis_exception_carries_context(): void
    {
        $e = new MartisException('Error', 'err', ['key' => 'val'], 503);

        $this->assertSame(['key' => 'val'], $e->context());
        $this->assertSame(503, $e->httpStatus());
    }

    // -------------------------------------------------------------------------
    // ValidationException
    // -------------------------------------------------------------------------

    public function test_validation_exception_has_422_status(): void
    {
        $e = new ValidationException([]);
        $this->assertSame(422, $e->httpStatus());
    }

    public function test_validation_exception_from_field_errors(): void
    {
        $e = ValidationException::fromFieldErrors([
            'email' => ['The email is required.'],
            'name' => ['The name must be at least 2 characters.'],
        ]);

        $errors = $e->errors();
        $this->assertCount(2, $errors);

        $emailError = collect($errors)->firstWhere('field', 'email');
        $this->assertNotNull($emailError);
        $this->assertSame('required', $emailError['code']);

        $nameError = collect($errors)->firstWhere('field', 'name');
        $this->assertNotNull($nameError);
        $this->assertSame('min', $nameError['code']);
    }

    public function test_validation_exception_for_single_field(): void
    {
        $e = ValidationException::forField('slug', 'Slug is taken.', 'unique');

        $this->assertCount(1, $e->errors());
        $this->assertSame('slug', $e->errors()[0]['field']);
        $this->assertSame('unique', $e->errors()[0]['code']);
    }

    public function test_validation_exception_errors_by_field(): void
    {
        $e = ValidationException::fromFieldErrors([
            'email' => ['Required.', 'Must be valid.'],
            'name' => ['Too short.'],
        ]);

        $byField = $e->errorsByField();
        $this->assertArrayHasKey('email', $byField);
        $this->assertArrayHasKey('name', $byField);
        // First message wins
        $this->assertSame('Required.', $byField['email']);
    }

    public function test_validation_exception_to_array_includes_errors(): void
    {
        $e = ValidationException::forField('slug', 'Taken.', 'unique');
        $arr = $e->toArray();

        $this->assertArrayHasKey('errors', $arr);
        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertSame('validation_error', $arr['code']);
    }

    public function test_validation_exception_is_martis_exception(): void
    {
        $this->assertInstanceOf(MartisException::class, new ValidationException([]));
    }

    // -------------------------------------------------------------------------
    // AuthorizationException
    // -------------------------------------------------------------------------

    public function test_authorization_exception_has_403_status(): void
    {
        $e = new AuthorizationException;
        $this->assertSame(403, $e->httpStatus());
        $this->assertSame('unauthorized', $e->errorCode());
    }

    public function test_authorization_exception_for_action(): void
    {
        $e = AuthorizationException::forAction('delete', 'post');

        $this->assertStringContainsString('delete', $e->getMessage());
        $this->assertStringContainsString('post', $e->getMessage());
        $this->assertSame('unauthorized_action', $e->errorCode());
    }

    public function test_authorization_exception_for_action_without_resource(): void
    {
        $e = AuthorizationException::forAction('publish');

        $this->assertStringContainsString('publish', $e->getMessage());
        $this->assertSame('unauthorized_action', $e->errorCode());
    }

    public function test_authorization_exception_is_martis_exception(): void
    {
        $this->assertInstanceOf(MartisException::class, new AuthorizationException);
    }

    // -------------------------------------------------------------------------
    // ResourceNotFoundException
    // -------------------------------------------------------------------------

    public function test_resource_not_found_exception_has_404_status(): void
    {
        $e = new ResourceNotFoundException;
        $this->assertSame(404, $e->httpStatus());
        $this->assertSame('resource_not_found', $e->errorCode());
    }

    public function test_resource_not_found_for_record(): void
    {
        $e = ResourceNotFoundException::forRecord('posts', 42);

        $this->assertSame('posts', $e->resourceKey());
        $this->assertSame(42, $e->recordId());
        $this->assertStringContainsString('42', $e->getMessage());
        $this->assertStringContainsString('posts', $e->getMessage());
    }

    public function test_resource_not_found_for_resource_definition(): void
    {
        $e = ResourceNotFoundException::forResourceDefinition('missing-resource');

        $this->assertStringContainsString('missing-resource', $e->getMessage());
        $this->assertStringContainsString('not registered', strtolower($e->getMessage()));
    }

    public function test_resource_not_found_exception_is_martis_exception(): void
    {
        $this->assertInstanceOf(MartisException::class, new ResourceNotFoundException);
    }

    // -------------------------------------------------------------------------
    // Inheritance hierarchy
    // -------------------------------------------------------------------------

    public function test_all_exceptions_extend_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new MartisException);
        $this->assertInstanceOf(\RuntimeException::class, new ValidationException([]));
        $this->assertInstanceOf(\RuntimeException::class, new AuthorizationException);
        $this->assertInstanceOf(\RuntimeException::class, new ResourceNotFoundException);
    }
}
