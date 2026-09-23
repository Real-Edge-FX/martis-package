# PrimeReact theme source (upstream)

The SASS source of the PrimeReact 10 lara theme, from
[primefaces/primereact-sass-theme](https://github.com/primefaces/primereact-sass-theme)
at commit `0b17cdc` (2024-11-28), under the MIT licence in `LICENSE`.

Compiled with its own defaults, this source reproduces the
`lara-dark-indigo/theme.css` shipped in `primereact` 10.9.7 through 10.9.9
(every declaration matches, colours to within 1/255 of rounding).

## What is here

| Path | Upstream path |
|------|---------------|
| `theme-base/` | `theme-base/` |
| `lara/_variables.scss` | `themes/lara/lara-dark/_variables.scss` |
| `lara/_extensions.scss` | `themes/lara/lara-dark/_extensions.scss` |
| `LICENSE` | `LICENSE` |

Martis does not edit the variables here: `../_tokens.scss` sets them, before
this file is imported, to the `--martis-*` design tokens (every variable in
`lara/_variables.scss` is declared `!default`).

## Local changes

A few colours are literal in the source rather than variables. Each changed
line ends with a `// Martis:` comment:

- `lara/_extensions.scss`: the gap of the button focus ring takes
  `--martis-surface`, and the close-icon hover of messages and toasts takes
  `--martis-hover`.
- `theme-base/components/messages/_message.scss` and
  `theme-base/components/messages/_toast.scss`: the close-icon hover takes
  `--martis-hover`.

## Updating

Copy the same paths from a newer upstream commit, re-apply the changes marked
`// Martis:`, and run the theme tests (`PrimeReactThemeTokensTest` and
`primereactTheme.test.ts`): they fail on a colour that does not read a token.
