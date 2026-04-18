<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;

/**
 * HasOneThrough — reaches a single distant record through an
 * intermediate model.
 *
 * Laravel Nova v5 parity: HasOneThrough.
 * Reference: https://nova.laravel.com/docs/v5/resources/relationships#HasOneThrough
 *
 * The relationship on the parent model is defined as
 *   `hasOneThrough(Owner::class, Car::class)`
 * and the field renders visually like `HasOne` — read-only, because
 * the traversal goes through an intermediate the UI cannot create.
 *
 * ⭐ Martis differentials:
 *  - **Read-only by default** — Nova never creates/edits Through
 *    records from the parent resource either, but doesn't document
 *    it. Martis makes this explicit and safe.
 *  - **`throughBreadcrumb(bool $enabled = true)`** — ships a tooltip
 *    describing the intermediate hop (e.g. `Project → Client →
 *    Account Manager`), resolved from the relation's intermediate
 *    table name. Nova does not surface this.
 */
class HasOneThrough extends HasOne
{
    protected bool $showThroughBreadcrumb = false;

    public function __construct(string $attribute, string $label, string $relationship = '')
    {
        parent::__construct($attribute, $label, $relationship);

        // Through relationships are traversal-only — the parent resource
        // cannot create the intermediate, so Create/Edit/Delete have
        // no meaningful target.
        $this->canCreateRelated = false;
        $this->canUpdateRelated = false;
        $this->canDeleteRelated = false;
    }

    public function type(): string
    {
        return 'has_one_through';
    }

    /**
     * ⭐ Martis differential — enable the "through" breadcrumb tooltip
     * on the detail panel.
     */
    public function throughBreadcrumb(bool $enabled = true): static
    {
        $this->showThroughBreadcrumb = $enabled;

        return $this;
    }

    public function hasThroughBreadcrumb(): bool
    {
        return $this->showThroughBreadcrumb;
    }

    public function validateRelationship(Model $model): void
    {
        if (! method_exists($model, $this->relationship)) {
            throw new \InvalidArgumentException(
                'Model '.get_class($model)." does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentHasOneThrough) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' is not a hasOneThrough relationship.'
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
        ];
    }
}
