import { useState, useEffect, useRef, useCallback } from "react"
import type { FieldDisplayProps, FieldInputProps } from "./types"
import { Eye, EyeSlash, X } from "@phosphor-icons/react"
import { BASE_PATH } from "@/lib/config"
import { useTranslation } from 'react-i18next'
import "trix/dist/trix.css"
import "trix"

function ImageModal({ src, onClose }: { src: string; onClose: () => void }) {
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
      onClick={onClose}
    >
      <button
        type="button"
        onClick={onClose}
        className="absolute top-4 right-4 text-white hover:text-gray-300 z-50"
        aria-label="Close"
      >
        <X size={28} weight="bold" />
      </button>
      <img
        src={src}
        alt=""
        className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  )
}

export function TrixFieldDisplay({ field, value }: FieldDisplayProps) {
  const { t } = useTranslation('messages')
  const contentRef = useRef<HTMLDivElement>(null)
  const [modalSrc, setModalSrc] = useState<string | null>(null)

  const ext = field as unknown as Record<string, unknown>
  const imageClickBehavior = (ext.imageClickBehavior as string) || 'modal'

  if (value === null || value === undefined || value === "") {
    return <span className="martis-text-muted">&mdash;</span>
  }

  const alwaysShow = (ext.alwaysShow as boolean) ?? false
  const [expanded, setExpanded] = useState(alwaysShow)

  // Intercept image clicks inside trix content
  useEffect(() => {
    if (!expanded || !contentRef.current) return

    function handleClick(e: MouseEvent) {
      const target = e.target as HTMLElement
      // Check if clicked element is an image or an anchor wrapping an image
      let img: HTMLImageElement | null = null
      if (target.tagName === 'IMG') {
        img = target as HTMLImageElement
      } else if (target.tagName === 'A' && target.querySelector('img')) {
        img = target.querySelector('img')
      }

      if (!img) return

      e.preventDefault()
      e.stopPropagation()

      const src = img.src
      if (imageClickBehavior === 'modal') {
        setModalSrc(src)
      } else if (imageClickBehavior === 'new_tab') {
        window.open(src, '_blank', 'noopener')
      }
      // 'same_page' — default browser behavior (do nothing, let link navigate)
    }

    const el = contentRef.current
    el.addEventListener('click', handleClick)
    return () => el.removeEventListener('click', handleClick)
  }, [expanded, imageClickBehavior])

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
        ref={contentRef}
        className="martis-trix-detail prose dark:prose-invert max-w-none text-sm trix-content"
        style={{ cursor: imageClickBehavior !== 'same_page' ? 'default' : undefined }}
        dangerouslySetInnerHTML={{ __html: String(value) }}
      />
      {modalSrc && <ImageModal src={modalSrc} onClose={() => setModalSrc(null)} />}
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

        // Auto-prepend https:// for URLs without a protocol
        const linkBtn = toolbar.querySelector('.trix-dialog .trix-button[data-trix-method="setAttribute"]')
        if (linkBtn) {
          linkBtn.addEventListener("click", () => {
            const urlInput = toolbar.querySelector('.trix-dialog input[name="href"]') as HTMLInputElement
              || toolbar.querySelector('.trix-dialog input[type="text"]') as HTMLInputElement
            if (urlInput && urlInput.value && !/^(https?|mailto|tel|ftp):/i.test(urlInput.value)) {
              urlInput.value = "https://" + urlInput.value
            }
          }, true) // capture phase - runs before Trix reads the value
        }
      }

      // Apply toolbar size from field config
      const toolbarSize = (field as Record<string, unknown>).toolbarSize as string | undefined
      if (toolbarSize) {
        const tb = (editor as HTMLElement).previousElementSibling as HTMLElement
        if (tb) {
          tb.classList.add("martis-trix-toolbar-" + toolbarSize)
        }
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
