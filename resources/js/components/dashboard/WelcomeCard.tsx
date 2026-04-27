import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { config } from '@/lib/config'
import { usePrefersReducedMotion } from '@/lib/usePrefersReducedMotion'

interface WelcomeCardProps {
  /** Optional heading override. Falls back to the `welcome_card_heading` translation. */
  heading?: string
  /** Optional description override. Falls back to the `welcome_card_description` translation. */
  description?: string
  /** Optional version override. Defaults to `config.version` (resolved server-side from the installed package tag). */
  version?: string | null
}

/**
 * Hero welcome surface for the default Martis dashboard.
 *
 * Two soft "aurora" blobs drift over a dark-indigo gradient, a dot-grid
 * overlay gives the panel tactile depth, and the version badge catches a
 * shimmer on hover. A tiny parallax tilt (max 2deg) follows the pointer —
 * paused automatically when `prefers-reduced-motion` is set.
 *
 * The heading, description, and version are configurable via props; when
 * omitted they fall back to the `martis::resources` translations and the
 * package version surfaced by `MartisManager::version()`.
 */
export function WelcomeCard({ heading, description, version }: WelcomeCardProps = {}) {
  const { t } = useTranslation('resources')
  const rootRef = useRef<HTMLDivElement>(null)
  const reducedMotion = usePrefersReducedMotion()

  const resolvedHeading = heading ?? t('welcome_card_heading', { defaultValue: 'Welcome to Martis' })
  const resolvedDescription = description ?? t('welcome_card_description', {
    defaultValue: 'A modern admin engine for Laravel. Fully themed, component-driven, extension-ready.',
  })
  const resolvedVersion = version !== undefined ? version : config.version ?? null

  useEffect(() => {
    const el = rootRef.current
    if (!el) return
    // Skip the parallax tilt when motion is reduced via either the OS
    // setting (`prefers-reduced-motion: reduce`) or the user toggle
    // (`html[data-reduced-motion="true"]`). The hook is reactive, so
    // toggling either at runtime (via Preferences) immediately
    // pauses / resumes the listeners.
    if (reducedMotion) {
      // Reset any tilt left over from a previous active session.
      el.style.setProperty('--tilt-x', '0deg')
      el.style.setProperty('--tilt-y', '0deg')
      return
    }

    const onMove = (e: MouseEvent) => {
      const rect = el.getBoundingClientRect()
      const x = (e.clientX - rect.left) / rect.width - 0.5
      const y = (e.clientY - rect.top) / rect.height - 0.5
      el.style.setProperty('--tilt-x', `${(-y * 2).toFixed(2)}deg`)
      el.style.setProperty('--tilt-y', `${(x * 2).toFixed(2)}deg`)
      el.style.setProperty('--pointer-x', `${((e.clientX - rect.left) / rect.width) * 100}%`)
      el.style.setProperty('--pointer-y', `${((e.clientY - rect.top) / rect.height) * 100}%`)
    }
    const onLeave = () => {
      el.style.setProperty('--tilt-x', '0deg')
      el.style.setProperty('--tilt-y', '0deg')
    }

    el.addEventListener('mousemove', onMove)
    el.addEventListener('mouseleave', onLeave)
    return () => {
      el.removeEventListener('mousemove', onMove)
      el.removeEventListener('mouseleave', onLeave)
    }
  }, [reducedMotion])

  return (
    <>
      <style>{`
        @keyframes mwc-float-a {
          0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
          33%      { transform: translate3d(18%, -12%, 0) scale(1.15); }
          66%      { transform: translate3d(-10%, 10%, 0) scale(0.95); }
        }
        @keyframes mwc-float-b {
          0%, 100% { transform: translate3d(0, 0, 0) scale(1.1); }
          50%      { transform: translate3d(-22%, 16%, 0) scale(0.9); }
        }
        @keyframes mwc-shimmer {
          0%   { background-position: -200% 0; }
          100% { background-position: 200% 0; }
        }
        /* Reduce-motion handling lives in martis.css alongside the
           other indicator-style exceptions (see the "Indeterminate
           progress indicators" block). The aurora blobs and the
           badge shimmer keep their slow cycle (14 s / 18 s / 3.5 s)
           because they sit below the vestibular threshold; the
           pointer-driven parallax tilt is still suppressed at the
           React layer via the usePrefersReducedMotion hook below. */
      `}</style>

      <div
        ref={rootRef}
        className="mwc-root"
        style={{
          position: 'relative',
          borderRadius: 'var(--martis-radius-xl, 16px)',
          padding: 'var(--mwc-pad-y, 2.25rem) var(--mwc-pad-x, 2.5rem)',
          color: 'var(--martis-brand-text, #fff)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: '1.5rem',
          overflow: 'hidden',
          minHeight: 'var(--mwc-min-h, 120px)',
          background:
            'radial-gradient(circle at var(--pointer-x, 20%) var(--pointer-y, 30%), var(--martis-brand-pointer-glow) 0%, transparent 55%),' +
            ' var(--martis-brand-gradient)',
          border: '1px solid rgba(255, 255, 255, 0.08)',
          boxShadow:
            '0 1px 0 rgba(255,255,255,0.06) inset, var(--martis-brand-shadow)',
          transform: 'perspective(1000px) rotateX(var(--tilt-x, 0)) rotateY(var(--tilt-y, 0))',
          transition: 'transform 220ms cubic-bezier(.2,.7,.3,1)',
          transformStyle: 'preserve-3d',
        }}
      >
        {/* Aurora blob A — cyan */}
        <div
          className="mwc-blob"
          aria-hidden="true"
          style={{
            position: 'absolute',
            top: '-35%',
            left: '10%',
            width: '55%',
            height: '220%',
            background:
              'radial-gradient(circle, var(--martis-brand-aurora-cyan) 0%, transparent 70%)',
            filter: 'blur(30px)',
            animation: 'mwc-float-a 14s ease-in-out infinite',
            pointerEvents: 'none',
          }}
        />
        {/* Aurora blob B — magenta */}
        <div
          className="mwc-blob"
          aria-hidden="true"
          style={{
            position: 'absolute',
            bottom: '-45%',
            right: '-10%',
            width: '55%',
            height: '220%',
            background:
              'radial-gradient(circle, var(--martis-brand-aurora-pink) 0%, transparent 70%)',
            filter: 'blur(36px)',
            animation: 'mwc-float-b 18s ease-in-out infinite',
            pointerEvents: 'none',
          }}
        />
        {/* Dot grid overlay — gives the surface tactile depth */}
        <div
          aria-hidden="true"
          style={{
            position: 'absolute',
            inset: 0,
            backgroundImage:
              'radial-gradient(circle, var(--martis-brand-grid-dot) 1px, transparent 1px)',
            backgroundSize: '14px 14px',
            maskImage:
              'radial-gradient(ellipse at center, black 40%, transparent 85%)',
            WebkitMaskImage:
              'radial-gradient(ellipse at center, black 40%, transparent 85%)',
            pointerEvents: 'none',
          }}
        />

        <div style={{ flex: 1, position: 'relative', zIndex: 1 }}>
          <h2
            style={{
              margin: 0,
              fontSize: '1.625rem',
              fontWeight: 700,
              letterSpacing: '-0.01em',
              lineHeight: 1.2,
              textShadow: '0 2px 20px rgba(0,0,0,0.35)',
            }}
          >
            {resolvedHeading}
          </h2>
          <p
            style={{
              margin: '0.5rem 0 0',
              opacity: 0.85,
              fontSize: '0.9375rem',
              lineHeight: 1.55,
              maxWidth: 620,
            }}
          >
            {resolvedDescription}
          </p>
        </div>

        {resolvedVersion && (
          <div
            className="mwc-badge"
            style={{
              position: 'relative',
              zIndex: 1,
              flexShrink: 0,
              padding: '0.45rem 0.85rem',
              borderRadius: 999,
              background:
                'linear-gradient(110deg, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0.06) 100%)',
              border: '1px solid rgba(255, 255, 255, 0.22)',
              backdropFilter: 'blur(10px)',
              WebkitBackdropFilter: 'blur(10px)',
              fontSize: '0.75rem',
              fontWeight: 600,
              letterSpacing: '0.04em',
              fontFamily: 'var(--martis-font-mono)',
              overflow: 'hidden',
              isolation: 'isolate',
            }}
          >
            <span
              className="mwc-shimmer"
              aria-hidden="true"
              style={{
                position: 'absolute',
                inset: 0,
                background:
                  'linear-gradient(110deg, transparent 20%, rgba(255,255,255,0.28) 50%, transparent 80%)',
                backgroundSize: '200% 100%',
                animation: 'mwc-shimmer 3.5s linear infinite',
                mixBlendMode: 'overlay',
                pointerEvents: 'none',
              }}
            />
            {/^\d/.test(resolvedVersion) ? `v${resolvedVersion}` : resolvedVersion}
          </div>
        )}
      </div>
    </>
  )
}
