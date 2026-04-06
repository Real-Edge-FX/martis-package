<?php

namespace Martis;

/**
 * Predefined redirect targets for override components.
 *
 * Use with Override::redirectAfter() to control where the user
 * navigates after a create/update action within an override.
 *
 * For custom URLs with dynamic placeholders, pass a string directly:
 *   ->redirectAfter('/resources/{resource}/{id}/preview')
 *
 * Available placeholders in custom URLs:
 *   {id}       — ID of the created/updated record
 *   {resource} — URI key of the resource (e.g. "projects")
 */
enum RedirectAfter: string
{
    /** Navigate to the record detail page (default behavior). */
    case DETAIL = 'detail';

    /** Stay on the resource index / list page. */
    case INDEX = 'index';

    /** Navigate to the edit page of the record. */
    case EDIT = 'edit';

    /** Stay on the create page (useful for batch creation). */
    case CREATE = 'create';

    /** Navigate to the Martis dashboard. */
    case DASHBOARD = 'dashboard';

    /** Do not navigate — the override component controls navigation. */
    case STAY = 'stay';
}
