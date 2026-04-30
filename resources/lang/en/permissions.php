<?php

return [
    // Help text rendered next to the `name` field on a Permission form.
    // The Slug field accepts dot-notation (default separator `.`) so the
    // copy nudges the dev toward the Spatie convention.
    'name_help' => 'A symbolic identifier checked in code via `$user->can(...)` or middleware (e.g. `dashboard.home.view`, `users.create`). Free format — pick a convention and stick to it.',

    // Help text for the `guard_name` field on Permission AND Role forms.
    // `guard_name` ties the row to one of the auth guards configured in
    // `config/auth.guards`. Most apps only ever use `web`.
    'guard_help' => 'Auth guard this permission applies to. Most apps only have one guard (`web`). Set to a different value only if your app exposes multiple guards (e.g. `web` for the panel, `api` for a separate mobile app).',

    // Optional explanation for `guard_name` on the Role form. Same field,
    // but the constraint here matters: a Role can only carry Permissions
    // of the same guard.
    'role_guard_help' => 'Auth guard this role applies to. A role can only be assigned permissions belonging to the SAME guard.',

    // Long-form explanation surfaced in the docs and (optionally) in a
    // tooltip overlay. Renders as plain text.
    'multi_guard_explanation' => 'Spatie Permission requires every permission and every role to declare a `guard_name`. The two must match for a role to receive a permission. Most apps run on a single guard (`web`); multi-guard setups are rare and typically split admin (`web`) from a public API (`api`).',
];
