import type { Config } from 'tailwindcss'
import preset from './tailwind.preset.js'

export default {
  // The package dogfoods the preset it ships to consumers, so `martis-*`
  // utilities (`text-martis-accent`, `bg-martis-accent-bg-light`,
  // `text-martis-accent-contrast`, …) resolve inside the bundle as well.
  // The preset only *extends* the theme, so the stock palette stays intact.
  presets: [preset as Config],
  // Tests and their helpers never ship: a token in one (`!block` in a
  // condition) would otherwise add a utility to the published CSS.
  content: [
    './resources/js/**/*.{ts,tsx}',
    '!./resources/js/**/*.test.{ts,tsx}',
    '!./resources/js/test-support/**',
    './resources/views/**/*.blade.php',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#6366f1',
          dark: '#4f46e5',
        },
      },
    },
  },
  corePlugins: {
    preflight: false,
  },
  plugins: [],
} satisfies Config

