<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Boolean toggle field.
 *
 * Renders as a checkbox or toggle switch in the React frontend.
 * Resolves the model attribute as a strict boolean.
 */
class Boolean extends Field
{
    protected ?string $trueLabel = null;

    protected ?string $falseLabel = null;

    /**
     * Badge variants used when rendering the boolean on index/detail
     * surfaces. Accepts either a semantic keyword (success, danger,
     * warning, info, neutral) or a hex string like `#22C55E`.
     */
    protected string $trueColor = 'success';

    protected string $falseColor = 'neutral';

    /**
     * Type.
     */
    public function type(): string
    {
        return 'boolean';
    }

    /**
     * Customize the label shown for a true value (index/detail views).
     */
    public function trueLabel(string $label): static
    {
        $this->trueLabel = $label;

        return $this;
    }

    /**
     * Customize the label shown for a false value (index/detail views).
     */
    public function falseLabel(string $label): static
    {
        $this->falseLabel = $label;

        return $this;
    }

    /**
     * Colour for the true pill (semantic keyword or hex).
     */
    public function trueColor(string $color): static
    {
        $this->trueColor = $color;

        return $this;
    }

    /**
     * Colour for the false pill (semantic keyword or hex).
     */
    public function falseColor(string $color): static
    {
        $this->falseColor = $color;

        return $this;
    }

    /**
     * Convenience: set both colours in a single call.
     */
    public function colors(string $trueColor, string $falseColor): static
    {
        $this->trueColor = $trueColor;
        $this->falseColor = $falseColor;

        return $this;
    }

    /**
     * Resolve the attribute as a strict boolean.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $raw = parent::resolve($model, $attribute);

        return (bool) $raw;
    }

    /**
     * Cast the incoming value to bool before setting on model.
     */
    public function fill(Model $model, mixed $value): void
    {
        parent::fill($model, (bool) $value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'trueLabel' => $this->trueLabel ?? __('martis::messages.yes'),
            'falseLabel' => $this->falseLabel ?? __('martis::messages.no'),
            'trueColor' => $this->trueColor,
            'falseColor' => $this->falseColor,
        ];
    }

    /** {@inheritDoc} */
    protected function defaultColumnWidth(): array
    {
        return ['width' => '120px'];
    }
}
