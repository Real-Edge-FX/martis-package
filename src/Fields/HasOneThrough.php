<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;

/**
 * HasOneThrough — reaches a single distant record through an
 * intermediate model.
 *
 * The relationship on the parent model is defined as
 *   `hasOneThrough(Owner::class, Car::class)`
 * and the field renders visually like `HasOne` — without a Create
 * button, because the traversal goes through an intermediate the UI
 * cannot create.
 *
 * ⭐ Martis differentials:
 *  - **No create through the relationship, enforced** — as in Nova, a
 *    Through record cannot be created from the parent resource and
 *    `canCreate()` has no effect, because the traversal goes through an
 *    intermediate model the UI cannot populate; the has-one endpoints
 *    also refuse a create through the relationship with a 403. Edit and
 *    Delete work as on `HasOne`, under the related resource's policies.
 *  - **`throughBreadcrumb(bool $enabled = true)`** — ships a tooltip
 *    describing the intermediate hop (e.g. `Project → Client →
 *    Account Manager`), resolved from the relation's intermediate
 *    table name.
 */
class HasOneThrough extends HasOne
{
    protected bool $showThroughBreadcrumb = false;

    protected ?string $throughBreadcrumbText = null;

    public function __construct(string $attribute, string $label, string $relationship = '')
    {
        parent::__construct($attribute, $label, $relationship);

        // No create through a Through relationship, as in Nova: it is a
        // traversal — there is no direct FK to populate (the intermediate
        // model is ambiguous) — and HasOneController refuses one (403).
        $this->canCreateRelated = false;
    }

    public function type(): string
    {
        return 'has_one_through';
    }

    /**
     * No effect: the panel of a Through relationship never offers Create.
     * Kept callable so a resource that calls it still loads.
     */
    public function canCreate(bool $value = true): static
    {
        return $this;
    }

    /**
     * ⭐ Martis differential — enable the breadcrumb tooltip on the
     * detail panel. Optionally accepts a custom text that overrides the
     * default "Indirect relationship accessed through :relationship".
     *
     * Usage:
     *   ->throughBreadcrumb()                           // default i18n string
     *   ->throughBreadcrumb(true, 'Via client')         // fixed text
     *   ->throughBreadcrumb(false)                      // disabled
     */
    public function throughBreadcrumb(bool $enabled = true, ?string $text = null): static
    {
        $this->showThroughBreadcrumb = $enabled;
        $this->throughBreadcrumbText = $text;

        return $this;
    }

    public function hasThroughBreadcrumb(): bool
    {
        return $this->showThroughBreadcrumb;
    }

    public function getThroughBreadcrumbText(): ?string
    {
        return $this->throughBreadcrumbText;
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
