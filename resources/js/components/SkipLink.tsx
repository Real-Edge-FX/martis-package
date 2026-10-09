import { useTranslation } from 'react-i18next'
import { MAIN_CONTENT_ID, NAVIGATION_ID } from '@/lib/shellIds'

export { MAIN_CONTENT_ID, NAVIGATION_ID }

/**
 * A skip link (WCAG 2.2, 2.4.1 Bypass Blocks): off-screen until it takes
 * keyboard focus, it moves focus to the element with the id `targetId`.
 * Focus moves in code, so the router's URL and hash stay as they are.
 */
function SkipTo({ targetId, label }: { targetId: string; label: string }) {
  return (
    <a
      href={`#${targetId}`}
      className="martis-skip-link"
      onClick={(event) => {
        event.preventDefault()
        document.getElementById(targetId)?.focus()
      }}
    >
      {label}
    </a>
  )
}

/**
 * "Skip to main content": the first focusable element of the sidebar and
 * topnav layouts. It moves focus past the navigation to the `<main>`
 * landmark.
 */
export function SkipLink() {
  const { t } = useTranslation('navigation')

  return <SkipTo targetId={MAIN_CONTENT_ID} label={t('skip_to_content', 'Skip to main content')} />
}

/**
 * "Skip to navigation" (v2.7.0): rendered right after {@link SkipLink}, it
 * moves focus to the menu's `<nav>` landmark. The sidebar layout points it at
 * the menu button on mobile, where the menu is a closed drawer.
 */
export function SkipToNavigationLink({ targetId = NAVIGATION_ID }: { targetId?: string }) {
  const { t } = useTranslation('navigation')

  return <SkipTo targetId={targetId} label={t('skip_to_navigation', 'Skip to navigation')} />
}
