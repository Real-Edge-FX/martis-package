import { useMemo } from "react"
import type { FieldDisplayProps, FieldInputProps } from "./types"
import CodeMirror from "@uiw/react-codemirror"
import { javascript } from "@codemirror/lang-javascript"
import { html } from "@codemirror/lang-html"
import { css } from "@codemirror/lang-css"
import { json } from "@codemirror/lang-json"
import { markdown as mdLang } from "@codemirror/lang-markdown"
import { php } from "@codemirror/lang-php"
import { python } from "@codemirror/lang-python"
import { sql } from "@codemirror/lang-sql"
import { xml } from "@codemirror/lang-xml"
import { yaml } from "@codemirror/lang-yaml"
import { sass } from "@codemirror/lang-sass"
import { vue } from "@codemirror/lang-vue"
import { oneDark } from "@codemirror/theme-one-dark"
import type { Extension } from "@codemirror/state"

/**
 * Detect whether the current page is in dark mode by checking
 * the <html> element class or the Martis CSS variable.
 */
function isDarkMode(): boolean {
  if (typeof document === "undefined") return true
  const htmlEl = document.documentElement
  // Martis: dark is default (has .dark class or no .light class)
  return !htmlEl.classList.contains("light") && (htmlEl.classList.contains("dark") || true)
}

/**
 * Map Nova language identifiers to CodeMirror 6 extensions.
 */
function getLanguageExtension(lang: string): Extension | null {
  switch (lang) {
    case "javascript":
      return javascript()
    case "htmlmixed":
      return html()
    case "css":
      return css()
    case "json":
      return json()
    case "markdown":
      return mdLang()
    case "php":
      return php()
    case "python":
    case "ruby":
      return python()
    case "sql":
      return sql()
    case "xml":
      return xml()
    case "yaml":
    case "yaml-frontmatter":
      return yaml()
    case "sass":
      return sass()
    case "vue":
      return vue()
    case "shell":
    case "dockerfile":
    case "nginx":
    case "twig":
    case "vim":
      return null
    default:
      return null
  }
}

export function CodeFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === "") {
    return <span className="text-gray-400 dark:text-gray-500">&mdash;</span>
  }

  const language =
    ((field as Record<string, unknown>).language as string) ?? "javascript"
  const langExt = useMemo(() => getLanguageExtension(language), [language])
  const dark = useMemo(() => isDarkMode(), [])
  const extensions = useMemo(() => {
    const exts: Extension[] = []
    if (langExt) exts.push(langExt)
    if (dark) exts.push(oneDark)
    return exts
  }, [langExt, dark])

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      <CodeMirror
        value={String(value)}
        readOnly
        editable={false}
        theme={dark ? "dark" : "light"}
        extensions={extensions}
        basicSetup={{ lineNumbers: true, foldGutter: false }}
        className="text-sm"
      />
    </div>
  )
}

export function CodeFieldInput({
  field,
  value,
  onChange,
  error,
}: FieldInputProps) {
  const language =
    ((field as Record<string, unknown>).language as string) ?? "javascript"
  const langExt = useMemo(() => getLanguageExtension(language), [language])
  const dark = useMemo(() => isDarkMode(), [])
  const extensions = useMemo(() => {
    const exts: Extension[] = []
    if (langExt) exts.push(langExt)
    if (dark) exts.push(oneDark)
    return exts
  }, [langExt, dark])

  return (
    <div className="flex flex-col gap-1">
      <div className="border border-gray-300 dark:border-gray-600 rounded overflow-hidden">
        <CodeMirror
          value={value === null || value === undefined ? "" : String(value)}
          onChange={(val) => onChange(val)}
          readOnly={field.readonly}
          editable={!field.readonly}
          theme={dark ? "dark" : "light"}
          extensions={extensions}
          basicSetup={{ lineNumbers: true, foldGutter: true }}
          className="text-sm"
          minHeight="150px"
        />
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
