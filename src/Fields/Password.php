<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Password field.
 *
 * Hidden from index and detail views by default.
 * Hashes the value automatically before persisting.
 */
class Password extends Field
{
    public function __construct(string $attribute, string $label)
    {
        parent::__construct($attribute, $label);
        $this->showOnIndex = false;
        $this->showOnDetail = false;
    }

    /**
     * Type.
     */
    public function type(): string
    {
        return 'password';
    }

    /**
     * Resolve.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        // Never expose password hashes
        return null;
    }

    /**
     * Fill.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        // Only update if a new password was provided
        if ($value !== null && $value !== '') {
            $model->setAttribute($this->attribute, Hash::make($value));
        }
    }
}
