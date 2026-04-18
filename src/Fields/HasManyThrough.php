<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;

/**
 * HasManyThrough — reaches many distant records through an
 * intermediate model.
 *
 * Laravel Nova v5 parity: HasManyThrough.
 * Reference: https://nova.laravel.com/docs/v5/resources/relationships#HasManyThrough
 *
 * The relationship on the parent model is defined as
 *   `hasManyThrough(Invoice::class, Project::class)`
 * and the field renders visually like `HasMany` — inline DataTable —
 * read-only because the traversal goes through an intermediate.
 *
 * ⭐ Martis differentials:
 *  - **Read-only by default** — no Create/Edit/Delete buttons; Nova
 *    tacitly doesn't support these but doesn't document it. Martis
 *    makes it explicit and safe.
 *  - **`throughBreadcrumb(bool $enabled = true)`** — tooltip describing
 *    the intermediate hop (e.g. `Client → Projects → Invoices`).
 *  - **`countBadge(bool $enabled = true)`** — shows a count pill on
 *    the parent's index cell, matching the `showRelationCount` API
 *    already available on `HasMany`. Default: on for Through (Nova
 *    doesn't expose this knob on Through at all).
 */
class HasManyThrough extends HasMany
{
    protected bool $showThroughBreadcrumb = false;

    protected bool $countBadge = true;

    public function __construct(string $attribute, string $label, string $relationship = '')
    {
        parent::__construct($attribute, $label, $relationship);

        // Through relationships are traversal-only.
        $this->canCreateRelated = false;
        $this->canUpdateRelated = false;
        $this->canDeleteRelated = false;
    }

    public function type(): string
    {
        return 'has_many_through';
    }

    /** ⭐ Martis differential — enable the "through" breadcrumb tooltip. */
    public function throughBreadcrumb(bool $enabled = true): static
    {
        $this->showThroughBreadcrumb = $enabled;

        return $this;
    }

    public function hasThroughBreadcrumb(): bool
    {
        return $this->showThroughBreadcrumb;
    }

    /** ⭐ Martis differential — toggle the count pill on the parent's index. */
    public function countBadge(bool $enabled = true): static
    {
        $this->countBadge = $enabled;

        return $this;
    }

    public function hasCountBadge(): bool
    {
        return $this->countBadge;
    }

    public function validateRelationship(Model $model): void
    {
        if (! method_exists($model, $this->relationship)) {
            throw new \InvalidArgumentException(
                'Model '.get_class($model)." does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentHasManyThrough) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a hasManyThrough relationship.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $base = parent::extraAttributes();

        return $base + [
            'throughBreadcrumb' => $this->showThroughBreadcrumb,
            'countBadge' => $this->countBadge,
        ];
    }
}
