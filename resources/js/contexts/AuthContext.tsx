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
import { signOut } from '@/lib/signOut'
import { isOnPage, passwordChangeUrl } from '@/lib/passwordChange'
import type { User } from '@/types'

export class TwoFactorRequiredError extends Error {
  constructor() {
    super('two_factor_required')
    this.name = 'TwoFactorRequiredError'
  }
}

/**
 * Thrown by `login()` when the workspace requires email verification
 * AND the user has not confirmed yet. The session is still alive on
 * the server (so the resend-link endpoint works), but the SPA must
 * route to /email/verify instead of the dashboard.
 *
 * Mirrors the TwoFactorRequiredError pattern.
 */
export class EmailVerificationRequiredError extends Error {
  constructor() {
    super('email_verification_required')
    this.name = 'EmailVerificationRequiredError'
  }
}

/**
 * Thrown by `login()` when the forced password change gate holds the user
 * (v2.3.0). The session is alive; the SPA must route to the change page
 * instead of the dashboard. Mirrors TwoFactorRequiredError.
 */
export class PasswordChangeRequiredError extends Error {
  constructor() {
    super('password_change_required')
    this.name = 'PasswordChangeRequiredError'
  }
}

interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string, keepSignedIn?: boolean) => Promise<void>
  logout: () => Promise<void>
  updateUser: (partial: Partial<User>) => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

interface AuthProviderProps {
  children: ReactNode
  /**
   * The signed-in user to start with, without the `/api/auth/user` request
   * (`null`: a guest). For a tree mounted outside the shell, such as a test
   * (MartisTestProvider). v2.3.0.
   */
  initialUser?: User | null
}

export function AuthProvider({ children, initialUser }: AuthProviderProps) {
  const [user, setUser] = useState<User | null>(initialUser ?? null)
  const [fetchOnMount] = useState(initialUser === undefined)
  const [isLoading, setIsLoading] = useState(fetchOnMount)

  useEffect(() => {
    if (!fetchOnMount) return
    api
      .get<User & { two_factor_pending?: boolean; email_verification_pending?: boolean; password_change_pending?: boolean } | null>('/api/auth/user')
      .then((u) => {
        if (u && typeof u === 'object' && u.two_factor_pending) {
          // Session is authenticated but 2FA challenge is pending.
          // Only redirect if not already on the challenge page to prevent loops.
          const challengePath = BASE_PATH + '/2fa/challenge'
          if (!window.location.pathname.startsWith(challengePath)) {
            window.location.href = challengePath
          }
          return
        }
        if (u && typeof u === 'object' && u.email_verification_pending) {
          // Session is authenticated but the user has not verified their
          // email. Bounce to /email/verify on every bootstrap (refresh,
          // deep-link reload) so the dashboard never paints behind the
          // gate. Skip if already on the verify page to avoid loops.
          const verifyPath = BASE_PATH + '/email/verify'
          if (!window.location.pathname.startsWith(verifyPath)) {
            window.location.href = verifyPath
          }
          return
        }
        if (u && typeof u === 'object' && u.password_change_pending) {
          // Held by the forced password change gate (v2.3.0): bootstrap on
          // the change page, never the shell. Skip on the page itself.
          const target = passwordChangeUrl()
          if (!isOnPage(target)) {
            window.location.href = target
          }
          return
        }
        setUser(u && typeof u === 'object' && 'id' in u ? u : null)
      })
      .catch(() => {})
      .finally(() => setIsLoading(false))
  }, [fetchOnMount])

  const login = useCallback(async (email: string, password: string, keepSignedIn = false) => {
    const res = await api.post<User & {
      two_factor_required?: boolean
      email_verification_required?: boolean
      password_change_required?: boolean
    }>('/api/auth/login', { email, password, keep_signed_in: keepSignedIn })
    if (res && typeof res === 'object' && res.two_factor_required) {
      // Backend signals that 2FA challenge is required before full session
      throw new TwoFactorRequiredError()
    }
    if (res && typeof res === 'object' && res.email_verification_required) {
      // Backend signals that the user must verify their email before any
      // protected surface paints. Session is alive (so the resend-link
      // endpoint behind `auth:` works); only the post-login destination
      // changes.
      throw new EmailVerificationRequiredError()
    }
    if (res && typeof res === 'object' && res.password_change_required) {
      // The forced password change gate holds the user (v2.3.0): the
      // session is alive, the destination is the change page.
      throw new PasswordChangeRequiredError()
    }
    setUser(res)
  }, [])

  const updateUser = useCallback((partial: Partial<User>) => {
    setUser((prev) => prev ? { ...prev, ...partial } : prev)
  }, [])

  const logout = useCallback(() => signOut(), [])

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
