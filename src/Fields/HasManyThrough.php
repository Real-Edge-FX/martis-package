<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;

/**
 * HasManyThrough — reaches many distant records through an
 * intermediate model.
 *
 * The relationship on the parent model is defined as
 *   `hasManyThrough(Invoice::class, Project::class)`
 * and the field renders visually like `HasMany` (an inline DataTable),
 * without a Create button, because the traversal goes through an
 * intermediate.
 *
 * ⭐ Martis differentials:
 *  - **No create through the relationship, enforced**: as in Nova, the
 *    panel offers no Create and `canCreate()` has no effect, because the
 *    traversal goes through an intermediate model the UI cannot
 *    populate; the has-many endpoints also refuse a create through the
 *    relationship with a 403. Edit / Delete / Restore / Force delete
 *    work as on `HasMany`, under the related resource's policies.
 *  - **`throughBreadcrumb(bool $enabled = true)`**: tooltip describing
 *    the intermediate hop (e.g. `Client → Projects → Invoices`).
 *  - **`countBadge(bool $enabled = true)`**: shows a count pill on
 *    the parent's index cell, matching the `showRelationCount` API
 *    already available on `HasMany`. Default: on for Through.
 */
class HasManyThrough extends HasMany
{
    protected bool $showThroughBreadcrumb = false;

    protected ?string $throughBreadcrumbText = null;

    protected bool $countBadge = true;

    public function __construct(string $attribute, string $label, string $relationship = '')
    {
        parent::__construct($attribute, $label, $relationship);

        // No create through a Through relationship, as in Nova: it is a
        // traversal with no direct FK to populate (the intermediate model
        // is ambiguous), and HasManyController refuses one (403).
        $this->canCreateRelated = false;
    }

    public function type(): string
    {
        return 'has_many_through';
    }

    /**
     * No effect: the panel of a Through relationship never offers Create.
     * Kept callable so a resource that calls it still loads; asking for
     * Create raises an E_USER_DEPRECATED notice (logged by Laravel on the
     * deprecations channel) instead of failing silently.
     */
    public function canCreate(bool $value = true): static
    {
        if ($value) {
            trigger_error(sprintf(
                'HasManyThrough::canCreate() on "%s" has no effect: records cannot be created through a Through relationship, as in Nova. Remove the call.',
                $this->relationship,
            ), E_USER_DEPRECATED);
        }

        return $this;
    }

    /**
     * ⭐ Martis differential — enable the breadcrumb tooltip. Accepts an
     * optional custom text that overrides the default i18n string.
     *
     * Usage: ->throughBreadcrumb(true, 'Projects managed through the clients of this team member')
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
