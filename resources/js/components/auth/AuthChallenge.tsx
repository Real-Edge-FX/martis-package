import { useCallback, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { WarningCircleIcon } from '@phosphor-icons/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { config } from '@/lib/config'
import { FieldError } from '@/components/auth/FieldError'

/**
 * The challenge (captcha) hook of the public auth forms (v2.21.0).
 *
 * Martis ships no captcha vendor. While `martis.auth.challenge.enabled` is on,
 * each form of a listed surface renders the component an app registered under
 * `auth:challenge`, sends the token it produces as `challenge_token`, and
 * renews it after a failed submit (a token is usable once). The server
 * (`martis.challenge:<surface>`, a `Martis\Contracts\ChallengeVerifier`) is
 * what enforces it; this half only collects the token.
 */

/** The component-registry key of the widget. */
export const CHALLENGE_REGISTRY_KEY = 'auth:challenge'

export type ChallengeSurface = 'login' | 'forgot' | 'reset' | 'magic_link' | 'register' | 'invitation'

/** What the registered `auth:challenge` component receives. */
export interface AuthChallengeProps {
  /** The public site key (`martis.auth.challenge.site_key`), if any. */
  siteKey: string | null
  /** The form it sits on, so one component can configure itself per form. */
  surface: string
  /** Hand the token up when the widget solved, or `null` when it expired or reset. */
  onToken: (token: string | null) => void
  /** Bumped after a failed submit: render a fresh challenge when it changes. */
  resetSignal: number
}

export interface AuthChallengeState {
  /** Whether a surface of this form is covered by the challenge. */
  active: boolean
  /** Enabled for this form but no `auth:challenge` component is registered. */
  misconfigured: boolean
  /** The surface the widget is told it sits on. */
  surface: string
  siteKey: string | null
  token: string | null
  resetSignal: number
  setToken: (token: string | null) => void
  /** Drop the token and ask the widget for a fresh one. Call after a failed submit. */
  reset: () => void
  /** The request-body field for `surface`: `{ challenge_token }` or nothing. */
  fields: (surface: ChallengeSurface) => { challenge_token?: string }
}

/**
 * State of the challenge on one form. `surfaces` are the surfaces the form
 * can submit to (the login page: `login` and `magic_link`); the widget shows
 * when any is covered, and the token is sent only to a covered one.
 */
export function useAuthChallenge(surfaces: ChallengeSurface | readonly ChallengeSurface[]): AuthChallengeState {
  const challenge = config.auth?.challenge
  const wanted = Array.isArray(surfaces) ? (surfaces as readonly ChallengeSurface[]) : [surfaces as ChallengeSurface]
  const covered = challenge?.enabled === true
    ? wanted.filter((surface) => challenge.appliesTo?.includes(surface))
    : []
  const active = covered.length > 0

  const [token, setToken] = useState<string | null>(null)
  const [resetSignal, setResetSignal] = useState(0)

  const reset = useCallback(() => {
    setToken(null)
    setResetSignal((n) => n + 1)
  }, [])

  return {
    active,
    misconfigured: active && !componentRegistry.has(CHALLENGE_REGISTRY_KEY),
    surface: covered[0] ?? '',
    siteKey: challenge?.siteKey ?? null,
    token,
    resetSignal,
    setToken,
    reset,
    fields: (surface) => (token !== null && covered.includes(surface) ? { challenge_token: token } : {}),
  }
}

/**
 * Renders the registered widget inside a form, or a visible configuration
 * error when the challenge is on and nothing is registered (a form that
 * cannot pass must say so, not fail on submit with no explanation).
 * Nothing at all for a form the challenge does not cover.
 */
export function AuthChallenge({ challenge, error }: { challenge: AuthChallengeState; error?: string }) {
  const { t } = useTranslation('auth')

  if (!challenge.active) return null

  if (challenge.misconfigured) {
    return (
      <div className="martis-auth-field-error" role="alert" data-testid="auth-challenge-misconfigured" style={{ marginBottom: 16 }}>
        <WarningCircleIcon size={14} weight="fill" aria-hidden="true" />
        <span>
          {t('challenge_not_configured', {
            defaultValue: 'The security check is turned on for this form, but no auth:challenge component is registered. Contact your administrator.',
          })}
        </span>
      </div>
    )
  }

  const Widget = componentRegistry.resolve(CHALLENGE_REGISTRY_KEY) as unknown as ComponentType<AuthChallengeProps>

  return (
    <div style={{ marginBottom: 16 }} data-testid="auth-challenge">
      <Widget
        siteKey={challenge.siteKey}
        surface={challenge.surface}
        onToken={challenge.setToken}
        resetSignal={challenge.resetSignal}
      />
      <FieldError message={error} />
    </div>
  )
}
