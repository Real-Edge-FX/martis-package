/**
 * Martis Tailwind preset.
 *
 * Surfaces every `--martis-*` design token as a Tailwind utility so
 * consumer apps can write `bg-martis-surface text-martis-text` instead
 * of `style={{ backgroundColor: 'var(--martis-surface)' }}`. Tokens
 * resolve at runtime via `var()` so the preset auto-tracks any theme
 * the user has active (dark / light / per-resource accent override).
 *
 * Usage in a consumer app's `tailwind.config.js`:
 *
 *     module.exports = {
 *       presets: [require('martis/tailwind.preset')],
 *       content: [
 *         './resources/**\/*.{tsx,ts,jsx,js,blade.php}',
 *         './vendor/martis/martis/resources/js/**\/*.tsx',
 *       ],
 *     }
 *
 * The preset is intentionally additive — it doesn't define a colour
 * palette of its own, so your existing tailwind theme stays intact.
 */

module.exports = {
  theme: {
    extend: {
      colors: {
        'martis-bg': 'var(--martis-bg)',
        'martis-surface': 'var(--martis-surface)',
        'martis-surface-alt': 'var(--martis-surface-alt)',
        'martis-sidebar': 'var(--martis-sidebar)',
        'martis-topbar': 'var(--martis-topbar)',
        'martis-card': 'var(--martis-card)',
        'martis-input-bg': 'var(--martis-input-bg)',

        'martis-text': 'var(--martis-text)',
        'martis-text-muted': 'var(--martis-text-muted)',
        'martis-text-faint': 'var(--martis-text-faint)',
        'martis-border': 'var(--martis-border)',

        'martis-accent': 'var(--martis-accent)',
        'martis-accent-hover': 'var(--martis-accent-hover)',
        'martis-accent-active': 'var(--martis-accent-active)',
        'martis-accent-contrast': 'var(--martis-accent-contrast)',
        'martis-accent-bg-light': 'var(--martis-accent-bg-light)',
        'martis-accent-bg': 'var(--martis-accent-bg)',

        'martis-success': 'var(--martis-success)',
        'martis-success-bg': 'var(--martis-success-bg)',
        'martis-warning': 'var(--martis-warning)',
        'martis-warning-bg': 'var(--martis-warning-bg)',
        'martis-danger': 'var(--martis-danger)',
        'martis-danger-bg': 'var(--martis-danger-bg)',
        'martis-info': 'var(--martis-info)',
        'martis-info-bg': 'var(--martis-info-bg)',

        'martis-hover': 'var(--martis-hover)',
        'martis-active': 'var(--martis-active)',
      },

      fontFamily: {
        'martis-sans': 'var(--martis-font-sans)',
        'martis-mono': 'var(--martis-font-mono)',
        'martis-heading': 'var(--martis-font-heading)',
      },

      fontSize: {
        'martis-xs': 'var(--martis-text-xs)',
        'martis-sm': 'var(--martis-text-sm)',
        'martis-base': 'var(--martis-text-base)',
        'martis-lg': 'var(--martis-text-lg)',
        'martis-xl': 'var(--martis-text-xl)',
        'martis-2xl': 'var(--martis-text-2xl)',
        'martis-3xl': 'var(--martis-text-3xl)',
      },

      fontWeight: {
        'martis-regular': 'var(--martis-weight-regular)',
        'martis-medium': 'var(--martis-weight-medium)',
        'martis-semibold': 'var(--martis-weight-semibold)',
        'martis-bold': 'var(--martis-weight-bold)',
      },

      borderRadius: {
        'martis-sm': 'var(--martis-radius-sm)',
        'martis-md': 'var(--martis-radius-md)',
        'martis-lg': 'var(--martis-radius-lg)',
        'martis-xl': 'var(--martis-radius-xl)',
        'martis-full': 'var(--martis-radius-full)',
      },

      boxShadow: {
        'martis-sm': 'var(--martis-shadow-sm)',
        'martis-md': 'var(--martis-shadow-md)',
        'martis-lg': 'var(--martis-shadow-lg)',
        'martis-peek': 'var(--martis-peek-shadow)',
      },

      transitionDuration: {
        'martis-fast': 'var(--martis-dur-fast)',
        'martis-base': 'var(--martis-dur-base)',
        'martis-slow': 'var(--martis-dur-slow)',
      },

      transitionTimingFunction: {
        'martis-standard': 'var(--martis-ease-standard)',
        'martis-decel': 'var(--martis-ease-decel)',
        'martis-accel': 'var(--martis-ease-accel)',
        'martis-spring': 'var(--martis-ease-spring)',
      },
    },
  },
}
