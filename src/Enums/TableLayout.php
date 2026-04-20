<?php

namespace Martis\Enums;

/**
 * How the index DataTable distributes column widths.
 *
 * Auto (default) lets the browser size each column by its content, with
 * optional `minWidth` / `maxWidth` hints per field. Pick this unless you
 * need pixel-perfect alignment across pages.
 *
 * Fixed locks every column to an explicit `width(...)` (or the field's
 * type default). Unwanted columns collapse to their smallest size, so
 * consumers must cover every visible column with an explicit width.
 */
enum TableLayout: string
{
    case Auto = 'auto';
    case Fixed = 'fixed';
}
