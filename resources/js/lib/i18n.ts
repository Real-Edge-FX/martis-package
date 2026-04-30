import i18n, { type Resource, type ResourceLanguage } from "i18next"
import { initReactI18next } from "react-i18next"
import { API_BASE_URL, config } from "./config"
import { queryClient } from "./query"

export function getLocale(): string {
  // Resolution order (v1.7.4):
  //   1. localStorage `martis-preferences.locale` — guest choice on
  //      login / register page, OR last applied locale of any user.
  //      Wins over SSR for the same reason readInitialPrefs() does:
  //      the SSR `locale` is just the server default for guests.
  //   2. SSR-injected `window.MartisConfig.locale` — for an
  //      authenticated user this is the resolver's view (DB row);
  //      for a guest it is the config default.
  //   3. Hard-coded `"en"`.
  //
  // We do NOT honour the persisted-source distinction here (unlike
  // readInitialPrefs) because i18n.init runs once and there is no
  // `source` field in `window.MartisConfig.locale`. The
  // PreferencesContext refetch for authenticated users (line 197 of
  // PreferencesContext.tsx) catches the post-login mismatch by
  // calling loadLocale() with the server's saved locale.
  let locale: string | undefined
  try {
    const raw = window.localStorage.getItem("martis-preferences")
    if (raw) {
      const cached = JSON.parse(raw)
      if (cached && typeof cached.locale === "string") {
        locale = cached.locale
      }
    }
  } catch {
    /* localStorage blocked / corrupt — fall through. */
  }
  if (!locale) {
    locale = config.locale ?? "en"
  }

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
