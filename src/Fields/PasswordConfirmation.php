<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Password confirmation field — companion to a Password field.
 *
 * The field is never persisted to the model (fill is a no-op). Backend
 * validation relies on Laravel's `confirmed` rule applied to the paired
 * `Password` field — this companion simply provides the second input and
 * the UX we ship around it.
 *
 * Martis differentials:
 *  - ⭐ Live match indicator — green tick / red cross in real time (React)
 *  - ⭐ Shared strength meter — reads `strengthMeter` from the paired field
 *  - ⭐ Synchronized visibility toggle — eye icon on both inputs mirror each other
 */
class PasswordConfirmation extends Field
{
    protected ?string $confirms = null;

    public function __construct(string $attribute, string $label)
    {
        parent::__construct($attribute, $label);
        // Companion field is only relevant on forms.
        $this->showOnIndex = false;
        $this->showOnDetail = false;
    }

    public function type(): string
    {
        return 'password_confirmation';
    }

    /**
     * Name the password attribute this confirmation is paired with (default:
     * the Laravel convention `password`).
     */
    public function confirms(string $attribute): static
    {
        $this->confirms = $attribute;

        return $this;
    }

    public function getConfirms(): string
    {
        return $this->confirms ?? 'password';
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        // intentionally empty
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'confirms' => $this->getConfirms(),
        ];
    }
}
