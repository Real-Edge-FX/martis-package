<?php

namespace Martis\Fields;

use Illuminate\Validation\Rule;
use Martis\Auth\GuardCatalog;

/**
 * Dropdown of auth guards configured in the host app's `config/auth.php`.
 *
 * Designed for `guard_name` columns on Spatie Permission / Role tables.
 * Most installs only have a single guard (`web`); the field still gives
 * the dev a confident UI to pick from instead of typing free text.
 *
 * Example usage in a PermissionResource:
 *
 * ```php
 * public function fields(Request $request): array
 * {
 *     return [
 *         ID::make()->sortable(),
 *         Slug::make('name')
 *             ->separator('.')
 *             ->reserved(['*'])
 *             ->help(__('martis::permissions.name_help'))
 *             ->required(),
 *         GuardSelect::make('guard_name')
 *             ->help(__('martis::permissions.guard_help'))
 *             ->required(),
 *     ];
 * }
 * ```
 *
 * The default value (when the form is rendered for a new record) is the
 * app's default guard — usually `web`. Override with `->only([...])`
 * to limit the dropdown to a curated subset.
 *
 * v1.8.0.
 */
class GuardSelect extends Select
{
    /** @var list<string>|null */
    protected ?array $allowList = null;

    /**
     * {@inheritdoc}
     *
     * Override the parent factory so every `GuardSelect::make()` call
     * automatically registers the guard-list resolver and sets the
     * default value to the app's configured default guard. Subclassing
     * `__construct` directly was rejected because `Field::__construct`
     * is `protected` and the parent factory is the canonical entry
     * point for field initialisation.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        /** @var static $field */
        $field = parent::make($attribute, $label);

        // Lazy resolver so the call to config() happens at schema-render
        // time — guarantees the host app has finished booting.
        $field->options(static function () use ($field) {
            $available = GuardCatalog::available();
            if ($field->allowList !== null) {
                $available = array_values(array_intersect($available, $field->allowList));
            }

            // Label === value: guard names are tokens (web, api, sanctum)
            // and translating them would be misleading.
            $out = [];
            foreach ($available as $name) {
                $out[$name] = $name;
            }

            return $out;
        });

        // Default = Laravel's configured default guard. Form rendering
        // picks this up when no value is present on the model.
        $field->default(GuardCatalog::default());

        // Server-side validation: reject any value not declared in
        // `config/auth.guards`. Without this guard a row could be
        // saved with a name like `sanctum` while the auth config has
        // no matching block — and Spatie Permission would later
        // crash on `Role::users()` (morphedByMany cannot resolve the
        // User model for an unconfigured guard). v1.8.2.
        $field->rules([
            'required',
            'string',
            'max:125',
            Rule::in(GuardCatalog::available()),
        ]);

        return $field;
    }

    /**
     * Restrict the dropdown to a subset of the configured guards.
     *
     * Useful when a Resource only makes sense against one specific
     * guard (e.g. `api` permissions vs `web` permissions managed in
     * separate sections of the admin).
     *
     * @param  list<string>  $guardNames
     */
    public function only(array $guardNames): static
    {
        $this->allowList = array_values(array_filter(
            array_map(static fn ($v) => (string) $v, $guardNames),
            static fn (string $v) => $v !== '',
        ));

        return $this;
    }
}
