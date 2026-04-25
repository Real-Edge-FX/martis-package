import i18n, { type Resource, type ResourceLanguage } from "i18next"
import { initReactI18next } from "react-i18next"
import { API_BASE_URL, config } from "./config"
import { queryClient } from "./query"

export function getLocale(): string {
  const locale = config.locale ?? "en"

  if (locale === "en_US" || locale === "en_GB") {
    return "en"
  }

  return locale
}

async function fetchTranslations(locale: string): Promise<ResourceLanguage> {
  try {
    const res = await fetch(`${API_BASE_URL}/api/translations/${locale}`, {
      cache: "no-store",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
    if (res.ok) {
      return (await res.json()) as ResourceLanguage
    }
  } catch {
    // silent — fall back to key names
  }
  return {}
}

/**
 * Load (or reload) a locale's translations into i18next and switch to it.
 * `changeLanguage` alone does not refetch resources — this helper keeps
 * the Preferences language picker honest so labels actually update.
 */
export async function loadLocale(locale: string): Promise<void> {
  const translations = await fetchTranslations(locale)
  for (const ns of Object.keys(translations)) {
    i18n.addResourceBundle(locale, ns, (translations as Record<string, object>)[ns], true, true)
  }
  await i18n.changeLanguage(locale)
  // Many UI strings (navigation labels, resource schemas, dashboards, lenses,
  // metric names) are translated server-side via `__()`. Invalidating the
  // react-query cache forces every protected endpoint to refetch under the
  // new locale instead of showing the previous language until staleTime
  // expires.
  await queryClient.invalidateQueries()
}

let initPromise: Promise<void> | null = null

export async function initI18n(): Promise<void> {
  if (initPromise !== null) return initPromise

  const locale = getLocale()

  initPromise = (async () => {
    const translations = await fetchTranslations(locale)

    await i18n.use(initReactI18next).init({
      resources: { [locale]: translations } as Resource,
      lng: locale,
      fallbackLng: "en",
      interpolation: { escapeValue: false },
      react: { useSuspense: false },
    })
  })()

  return initPromise
}

export default i18n
