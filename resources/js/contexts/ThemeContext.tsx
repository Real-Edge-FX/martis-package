import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { usePreferences } from '@/contexts/PreferencesContext'

type Theme = 'light' | 'dark'

interface ThemeContextValue {
  theme: Theme
  toggle: () => void
  setTheme: (t: Theme) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function resolveConcrete(theme: 'dark' | 'light' | 'system'): Theme {
  if (theme === 'system') {
    return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
  }
  return theme
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const { prefs, update } = usePreferences()

  const value = useMemo<ThemeContextValue>(() => {
    const concrete = resolveConcrete(prefs.theme)
    return {
      theme: concrete,
      toggle: () => { void update({ theme: concrete === 'dark' ? 'light' : 'dark' }) },
      setTheme: (t: Theme) => { void update({ theme: t }) },
    }
  }, [prefs.theme, update])

  return (
    <ThemeContext.Provider value={value}>
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
