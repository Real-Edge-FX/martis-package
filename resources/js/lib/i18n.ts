import i18n from "i18next"
import { initReactI18next } from "react-i18next"
import { BASE_PATH, config } from "./config"

export function getLocale(): string {
  return config.locale ?? "en"
}

let initPromise: Promise<void> | null = null

export async function initI18n(): Promise<void> {
  if (initPromise !== null) return initPromise

  const locale = getLocale()

  initPromise = (async () => {
    let translations: Record<string, Record<string, string>> = {}

    try {
      const res = await fetch(`${BASE_PATH}/api/translations/${locale}`, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
      if (res.ok) {
        translations = (await res.json()) as Record<string, Record<string, string>>
      }
    } catch {
      // silent fallback — i18n will return the key names
    }

    await i18n.use(initReactI18next).init({
      resources: { [locale]: translations },
      lng: locale,
      fallbackLng: "en",
      interpolation: { escapeValue: false },
      react: { useSuspense: false },
    })
  })()

  return initPromise
}

export default i18n
