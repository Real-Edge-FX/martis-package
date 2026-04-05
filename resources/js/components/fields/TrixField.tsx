import { useState, useEffect, useRef, useCallback } from "react"
import type { FieldDisplayProps, FieldInputProps } from "./types"
import { Eye, EyeSlash } from "@phosphor-icons/react"
import { BASE_PATH } from "@/lib/config"
import { useTranslation } from 'react-i18next'
import "trix/dist/trix.css"
import "trix"

export function TrixFieldDisplay({ field, value }: FieldDisplayProps) {
  const { t } = useTranslation('messages')
  if (value === null || value === undefined || value === "") {
    return <span className="martis-text-muted">&mdash;</span>
  }

  const alwaysShow =
    ((field as Record<string, unknown>).alwaysShow as boolean) ?? false
  const [expanded, setExpanded] = useState(alwaysShow)

  if (!expanded) {
    return (
      <button
        type="button"
        onClick={() => setExpanded(true)}
        className="inline-flex items-center gap-1.5 text-sm" style={{ color: "var(--martis-accent)" }}
      >
        <Eye size={16} weight="bold" />
        {t('show_content')}
      </button>
    )
  }

  return (
    <div className="relative">
      {!alwaysShow && (
        <button
          type="button"
          onClick={() => setExpanded(false)}
          className="inline-flex items-center gap-1.5 text-sm mb-2" style={{ color: "var(--martis-accent)" }}
        >
          <EyeSlash size={16} weight="bold" />
          {t('hide')}
        </button>
      )}
      <div
        className="martis-trix-detail prose dark:prose-invert max-w-none text-sm trix-content"
        dangerouslySetInnerHTML={{ __html: String(value) }}
      />
    </div>
  )
}

export function TrixFieldInput({
  field,
  value,
  onChange,
  error,
}: FieldInputProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const editorRef = useRef<HTMLElement | null>(null)
  const hiddenInputRef = useRef<HTMLInputElement | null>(null)
  const inputId = `trix-input-${field.attribute}`
  const initialized = useRef(false)
  const lastPropValue = useRef<string>("")
  const internalUpdate = useRef(false)

  const currentValue =
    value === null || value === undefined ? "" : String(value)

  const handleChange = useCallback(() => {
    if (hiddenInputRef.current) {
      internalUpdate.current = true
      onChange(hiddenInputRef.current.value)
    }
  }, [onChange])

  // Initialize Trix editor once
  useEffect(() => {
    if (!containerRef.current || initialized.current) return

    const input = document.createElement("input")
    input.id = inputId
    input.type = "hidden"
    input.value = currentValue
    containerRef.current.appendChild(input)
    hiddenInputRef.current = input
    lastPropValue.current = currentValue

    const editor = document.createElement("trix-editor")
    editor.setAttribute("input", inputId)
    editor.classList.add("trix-content")
    if (field.readonly) {
      editor.setAttribute("contenteditable", "false")
    }
    containerRef.current.appendChild(editor)
    editorRef.current = editor

    editor.addEventListener("trix-change", handleChange)

    // After Trix initializes, change link dialog input type from "url" to "text"
    // so the browser does not auto-validate with :invalid (which forces http/https prefix).
    // Users can enter any URL format and Trix handles it.
    editor.addEventListener("trix-initialize", () => {
      const toolbar = (editor as HTMLElement).previousElementSibling
      if (toolbar) {
        const selector = '.trix-dialog input[type="url"]'
        const urlInputs = toolbar.querySelectorAll(selector)
        urlInputs.forEach((inp) => {
          inp.setAttribute("type", "text")
          inp.setAttribute("placeholder", "Enter a URL\u2026")
        })
      }
    })

    // Handle file attachments
    const withFiles = (field as Record<string, unknown>).withFiles
    editor.addEventListener(
      "trix-attachment-add",
      ((event: Event) => {
        const attachment = (
          event as unknown as {
            attachment: {
              file: File | null
              remove: () => void
              setUploadProgress: (progress: number) => void
              setAttributes: (attrs: Record<string, string>) => void
            }
          }
        ).attachment
        if (!attachment.file) return

        if (!withFiles) {
          attachment.remove()
          return
        }

        const formData = new FormData()
        formData.append("file", attachment.file)

        const csrfMeta = document.querySelector(
          'meta[name="csrf-token"]',
        ) as HTMLMetaElement | null
        const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
        const headers: Record<string, string> = {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        }
        if (csrfMeta) {
          headers["X-CSRF-TOKEN"] = csrfMeta.content
        } else if (csrfMatch) {
          headers["X-XSRF-TOKEN"] = decodeURIComponent(csrfMatch[1])
        }

        attachment.setUploadProgress(10)

        fetch(`${BASE_PATH}/api/attachments/upload`, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          headers,
        })
          .then((response) => {
            attachment.setUploadProgress(50)
            if (!response.ok)
              throw new Error(`Upload failed: ${response.status}`)
            return response.json()
          })
          .then((data: { url: string; href?: string }) => {
            attachment.setUploadProgress(100)
            attachment.setAttributes({
              url: data.url,
              href: data.href || data.url,
            })
          })
          .catch((err) => {
            console.error("Trix attachment upload failed:", err)
            attachment.remove()
          })
      }) as EventListener,
    )

    // Allow file drop and toolbar attachment button
    editor.addEventListener("trix-file-accept", ((event: Event) => {
      if (!withFiles) {
        event.preventDefault()
      }
    }) as EventListener)

    initialized.current = true

    return () => {
      editor.removeEventListener("trix-change", handleChange)
    }
  }, [])

  // Sync external value changes into the editor (record loading on edit)
  useEffect(() => {
    if (!initialized.current || !editorRef.current) return

    if (internalUpdate.current) {
      internalUpdate.current = false
      lastPropValue.current = currentValue
      return
    }

    if (currentValue !== lastPropValue.current) {
      lastPropValue.current = currentValue
      const trixEditor = editorRef.current as HTMLElement & {
        editor?: { loadHTML: (html: string) => void }
      }
      if (trixEditor.editor) {
        trixEditor.editor.loadHTML(currentValue)
      }
      if (hiddenInputRef.current) {
        hiddenInputRef.current.value = currentValue
      }
    }
  }, [currentValue])

  return (
    <div className="flex flex-col gap-1">
      <div ref={containerRef} className="martis-trix-wrapper" />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
