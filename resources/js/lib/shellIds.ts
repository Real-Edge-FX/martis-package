/**
 * The element ids the built-in layouts render and the skip links target.
 * They are a stable contract: a replacement sidebar or top bar that renders
 * the same ids keeps the skip links and the mobile menu button working.
 */

/** The `<main>` landmark every built-in layout renders. */
export const MAIN_CONTENT_ID = 'martis-main'

/** The `<nav>` landmark of the sidebar and top-navigation menus. */
export const NAVIGATION_ID = 'martis-navigation'

/** The sidebar's root element: the drawer the mobile menu button controls. */
export const SIDEBAR_ID = 'martis-sidebar'

/** The top bar's mobile menu button, which opens the sidebar drawer. */
export const SIDEBAR_TOGGLE_ID = 'martis-sidebar-toggle'
