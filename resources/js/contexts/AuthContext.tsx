import {
  createContext,
  useContext,
  useState,
  useCallback,
  useEffect,
  type ReactNode,
} from 'react'
import { api } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'
import type { User } from '@/types'

export class TwoFactorRequiredError extends Error {
  constructor() {
    super('two_factor_required')
    this.name = 'TwoFactorRequiredError'
  }
}

interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  updateUser: (partial: Partial<User>) => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    api
      .get<User & { two_factor_pending?: boolean } | null>('/api/auth/user')
      .then((u) => {
        if (u && typeof u === 'object' && u.two_factor_pending) {
          // Session is authenticated but 2FA challenge is pending
          window.location.href = BASE_PATH + '/2fa/challenge'
          return
        }
        setUser(u && typeof u === 'object' && 'id' in u ? u : null)
      })
      .catch(() => {})
      .finally(() => setIsLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const res = await api.post<User & { two_factor_required?: boolean }>('/api/auth/login', { email, password })
    if (res && typeof res === 'object' && res.two_factor_required) {
      // Backend signals that 2FA challenge is required before full session
      throw new TwoFactorRequiredError()
    }
    setUser(res)
  }, [])

  const updateUser = useCallback((partial: Partial<User>) => {
    setUser((prev) => prev ? { ...prev, ...partial } : prev)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/api/auth/logout')
    } catch {
      // ignore — session may already be invalid
    }
    // Full page reload ensures server session is cleared and fresh CSRF token
    window.location.href = BASE_PATH + '/login'
  }, [])

  return (
    <AuthContext.Provider value={{ user, isLoading, login, logout, updateUser }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
