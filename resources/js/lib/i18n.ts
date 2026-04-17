import i18n, { type Resource, type ResourceLanguage } from "i18next"
import { initReactI18next } from "react-i18next"
import { API_BASE_URL, config } from "./config"

export function getLocale(): string {
  const locale = config.locale ?? "en"

  if (locale === "en_US" || locale === "en_GB") {
    return "en"
  }

  return locale
}

let initPromise: Promise<void> | null = null

export async function initI18n(): Promise<void> {
  if (initPromise !== null) return initPromise

  const locale = getLocale()

  initPromise = (async () => {
    let translations: ResourceLanguage = {}

    try {
      const res = await fetch(`${API_BASE_URL}/api/translations/${locale}`, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
      if (res.ok) {
        translations = (await res.json()) as ResourceLanguage
      }
    } catch {
      // silent fallback — i18n will return key names
    }

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
