<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Date / datetime picker field.
 *
 * Resolves Carbon / DateTime instances to an ISO-8601 string so the
 * React frontend always receives a consistent, serializable value.
 *
 * Supports date-only and datetime modes via the `withTime()` toggle.
 */
class Date extends Field
{
    protected bool $withTime = false;

    protected string $displayFormat = 'Y-m-d';

    protected string $storeFormat = 'Y-m-d';

    public function type(): string
    {
        return 'date';
    }

    /**
     * Enable date+time mode (datetime-local input in the frontend).
     */
    public function withTime(bool $value = true): static
    {
        $this->withTime = $value;
        $this->displayFormat = $value ? 'Y-m-d H:i:s' : 'Y-m-d';
        $this->storeFormat = $value ? 'Y-m-d H:i:s' : 'Y-m-d';

        return $this;
    }

    /**
     * Customize the format used when displaying or serializing the date.
     */
    public function format(string $format): static
    {
        $this->displayFormat = $format;

        return $this;
    }

    /**
     * Resolve the date field, normalizing Carbon / DateTime to an ISO string.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolve($model, $attribute);

        if ($value === null) {
            return null;
        }

        // Carbon and DateTime both expose format()
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->displayFormat);
        }

        // String passthrough — trust the model cast
        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'withTime' => $this->withTime,
            'displayFormat' => $this->displayFormat,
        ];
    }
}
