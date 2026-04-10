<?php

namespace Martis\Exceptions;

/**
 * Thrown when input data fails validation rules.
 *
 * Carries per-field error details so that the API layer can render
 * structured validation feedback without additional mapping.
 */
class ValidationException extends MartisException
{
    /**
     * @param  list<array{field: string, message: string, code: string}>  $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct($message, 'validation_error', [], 422);
    }

    /**
     * Build from a Laravel-style field => messages map.
     *
     * @param  array<string, list<string>>  $fieldErrors
     */
    public static function fromFieldErrors(array $fieldErrors, string $message = 'The given data was invalid.'): self
    {
        $errors = [];

        foreach ($fieldErrors as $field => $messages) {
            foreach ($messages as $msg) {
                $errors[] = [
                    'field' => $field,
                    'message' => $msg,
                    'code' => self::inferCode($msg),
                ];
            }
        }

        return new self($errors, $message);
    }

    /**
     * Build from a single field error.
     */
    public static function forField(string $field, string $message, string $code = 'invalid'): self
    {
        return new self([['field' => $field, 'message' => $message, 'code' => $code]]);
    }

    /**
     * @return list<array{field: string, message: string, code: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Group errors by field name for inline rendering.
     *
     * @return array<string, string>
     */
    public function errorsByField(): array
    {
        $result = [];

        foreach ($this->errors as $error) {
            if (! isset($result[$error['field']])) {
                $result[$error['field']] = $error['message'];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }

    private static function inferCode(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'required') => 'required',
            str_contains($lower, 'unique') || str_contains($lower, 'already') => 'unique',
            str_contains($lower, 'email') => 'email',
            str_contains($lower, 'min') || str_contains($lower, 'at least') => 'min',
            str_contains($lower, 'max') || str_contains($lower, 'may not') => 'max',
            default => 'invalid',
        };
    }
}
