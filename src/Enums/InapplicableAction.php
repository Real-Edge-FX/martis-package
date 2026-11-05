<?php

namespace Martis\Enums;

/**
 * How an action is drawn for a record it cannot run on (its verdict in the
 * row's `_actionAuthorization` map is false). The run refuses it either way.
 */
enum InapplicableAction: string
{
    /** Drawn greyed out and inert (the default). */
    case Disabled = 'disabled';

    /** Not drawn: no row button, no menu item, no detail-page entry. */
    case Hide = 'hide';

    /**
     * A row button keeps its slot, invisible and out of the tab order, so the
     * icons of the following actions line up from row to row. In a menu and
     * on the detail page it is left out, as Hide.
     */
    case KeepSlot = 'keep_slot';
}
