import { useTranslation } from 'react-i18next'

/** The id of the `<main>` landmark every built-in layout renders. */
export const MAIN_CONTENT_ID = 'martis-main'

/**
 * "Skip to main content" (WCAG 2.2, 2.4.1 Bypass Blocks). The first
 * focusable element of the sidebar and topnav layouts: off-screen until it
 * takes keyboard focus, it moves focus past the navigation to the `<main>`
 * landmark. Focus moves in code, so the router's URL and hash stay as they
 * are.
 */
export function SkipLink() {
  const { t } = useTranslation('navigation')

  return (
    <a
      href={`#${MAIN_CONTENT_ID}`}
      className="martis-skip-link"
      onClick={(event) => {
        event.preventDefault()
        document.getElementById(MAIN_CONTENT_ID)?.focus()
      }}
    >
      {t('skip_to_content', 'Skip to main content')}
    </a>
  )
}
