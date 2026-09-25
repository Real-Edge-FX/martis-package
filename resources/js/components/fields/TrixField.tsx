import { useState, useEffect, useId, useRef } from "react"
import { createPortal } from "react-dom"
import type { FieldDisplayProps, FieldInputProps } from "./types"
import { EyeIcon, EyeSlashIcon, XIcon } from "@phosphor-icons/react"
import { BASE_PATH } from "@/lib/config"
import { useTranslation } from 'react-i18next'
import { useModalHistoryBackToClose } from "@/lib/historyLock"
import "trix/dist/trix.css"
import "trix"

function ImageModal({ src, onClose }: { src: string; onClose: () => void }) {
  useModalHistoryBackToClose(true, onClose)

  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key !== 'Escape') return
      // The drawer underneath leaves this Escape alone (the modal history
      // lock below counts as a modal); stopping it also keeps any other
      // document listener from acting on it.
      e.stopImmediatePropagation()
      e.preventDefault()
      onClose()
    }
    // Capture phase so we run BEFORE listeners attached higher up (like the
    // drawer's keydown listener on document).
    document.addEventListener('keydown', handleKeyDown, true)
    return () => document.removeEventListener('keydown', handleKeyDown, true)
  }, [onClose])

  // Portal to <body> so the modal escapes the drawer/dialog stacking context
  // (the drawer is itself portal'd with its own z-50; rendering the modal
  // inside the drawer tree traps it behind the drawer's backdrop).
  return createPortal(
    <div
      className="fixed inset-0 flex items-center justify-center bg-black/70"
      style={{ zIndex: 9999 }}
      onClick={onClose}
    >
      <button
        type="button"
        onClick={onClose}
        className="absolute top-4 right-4 text-white hover:text-gray-300"
        style={{ zIndex: 10000 }}
        aria-label="Close"
      >
        <XIcon size={28} weight="bold" />
      </button>
      <img
        src={src}
        alt=""
        className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      />
    </div>,
    document.body,
  )
}

export function TrixFieldDisplay({ field, value }: FieldDisplayProps) {
  const { t } = useTranslation('messages')
  const contentRef = useRef<HTMLDivElement>(null)
  const [modalSrc, setModalSrc] = useState<string | null>(null)

  const ext = field as unknown as Record<string, unknown>
  const imageClickBehavior = (ext.imageClickBehavior as string) || 'modal'
  const linkClickBehavior = (ext.linkClickBehavior as string) || 'same_page'
  const alwaysShow = (ext.alwaysShow as boolean) ?? false

  // Every hook runs before the empty-value return below: the same element
  // renders a value that is set or cleared later (a refetch, polling, an
  // inline edit), and React requires the same hooks on every render. With no
  // value there is no content element, so the effect below does nothing.
  const [expanded, setExpanded] = useState(alwaysShow)

  // Intercept image + attachment link clicks inside trix content
  useEffect(() => {
    if (!expanded || !contentRef.current) return

    function handleClick(e: MouseEvent) {
      const target = e.target as HTMLElement

      // 0) Any click inside a Trix attachment figure — regardless of whether
      //    the user hit the image, the caption text, or whitespace — should
      //    open according to the configured behaviour. Trix stores the
      //    original file URL in `data-trix-attachment` JSON, so read from
      //    there as the canonical source.
      const fig = target.closest('figure.attachment') as HTMLElement | null
      if (fig) {
        let attachmentUrl: string | null = null
        const raw = fig.getAttribute('data-trix-attachment')
        if (raw) {
          try {
            const parsed = JSON.parse(raw) as { url?: string; href?: string }
            attachmentUrl = parsed.url || parsed.href || null
          } catch {
            // fall through
          }
        }
        // Preview figures may embed an <img>; fall back to its `src` when
        // the JSON is missing (e.g. for inline-pasted images).
        const inlineImg = fig.querySelector('img') as HTMLImageElement | null
        if (!attachmentUrl && inlineImg) attachmentUrl = inlineImg.src

        if (attachmentUrl) {
          e.preventDefault()
          e.stopPropagation()
          const isImage = fig.classList.contains('attachment--preview')
          if (isImage && imageClickBehavior === 'modal') {
            setModalSrc(attachmentUrl)
          } else if (linkClickBehavior === 'same_page') {
            window.location.href = attachmentUrl
          } else {
            window.open(attachmentUrl, '_blank', 'noopener,noreferrer')
          }
          return
        }
      }

      // 1) Bare <a href> outside any attachment figure (plain inline links).
      const anchor = target.closest('a[href]') as HTMLAnchorElement | null
      if (anchor && linkClickBehavior !== 'same_page') {
        e.preventDefault()
        e.stopPropagation()
        window.open(anchor.href, '_blank', 'noopener,noreferrer')
      }
    }

    const el = contentRef.current
    el.addEventListener('click', handleClick)

    // Ensure every attachment / inline link opens in a new tab (unless the
    // resource explicitly opted for `same_page`). Keeps middle-click /
    // cmd-click consistent with the click handler above.
    if (linkClickBehavior !== 'same_page') {
      el.querySelectorAll('a[href]').forEach((a) => {
        const anchor = a as HTMLAnchorElement
        anchor.setAttribute('target', '_blank')
        anchor.setAttribute('rel', 'noopener noreferrer')
      })
    }

    // Restore per-attachment size (persisted in `data-trix-attachment` JSON
    // by the editor's resize handle). Trix doesn't stamp the `<img>` width
    // itself on save — without this loop the display always renders at the
    // image's intrinsic size.
    el.querySelectorAll('figure.attachment').forEach((figNode) => {
      const fig = figNode as HTMLElement
      const raw = fig.getAttribute('data-trix-attachment')
      if (!raw) return
      try {
        const meta = JSON.parse(raw) as { width?: number; height?: number }
        if (typeof meta.width === 'number' && meta.width > 0) {
          fig.style.width = `${meta.width}px`
          fig.style.maxWidth = '100%'
          const img = fig.querySelector('img') as HTMLImageElement | null
          if (img) {
            img.setAttribute('width', String(meta.width))
            if (typeof meta.height === 'number' && meta.height > 0) {
              img.setAttribute('height', String(meta.height))
            }
            img.style.width = '100%'
            img.style.height = 'auto'
          }
        }
      } catch {
        // ignore malformed attachment JSON
      }
    })

    return () => el.removeEventListener('click', handleClick)
  }, [expanded, imageClickBehavior, linkClickBehavior, value])

  if (value === null || value === undefined || value === "") {
    return <span className="martis-text-muted">&mdash;</span>
  }

  if (!expanded) {
    return (
      <button
        type="button"
        onClick={() => setExpanded(true)}
        className="inline-flex items-center gap-1.5 text-sm" style={{ color: "var(--martis-accent)" }}
      >
        <EyeIcon size={16} weight="bold" />
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
          <EyeSlashIcon size={16} weight="bold" />
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
  const { t } = useTranslation('messages')
  const containerRef = useRef<HTMLDivElement>(null)
  const editorRef = useRef<HTMLElement | null>(null)
  const hiddenInputRef = useRef<HTMLInputElement | null>(null)
  // Trix finds its hidden input by id in the whole document, so the id is
  // unique per editor: another editor for the same attribute (the next row
  // of a Repeater, an inline-create modal over the form) has its own input.
  const inputId = `trix-input-${field.attribute}-${useId().replace(/:/g, "")}`
  const lastPropValue = useRef<string>("")
  const internalUpdate = useRef(false)

  // Localise the Trix toolbar tooltips by mutating `Trix.config.lang`
  // before the first editor instantiates. The toolbar uses these strings
  // for button `title` attributes, which we then migrate to
  // `data-pr-tooltip` (see the trix-initialize handler below) so the
  // PrimeReact tooltip surfaces them in the package style.
  useEffect(() => {
    const globalTrix = (window as unknown as { Trix?: { config?: { lang?: Record<string, string> } } }).Trix
    if (!globalTrix?.config?.lang) return
    Object.assign(globalTrix.config.lang, {
      attachFiles: t('trix_attach', { defaultValue: 'Attach Files' }),
      bold: t('trix_bold', { defaultValue: 'Bold' }),
      bullets: t('trix_bullets', { defaultValue: 'Bullets' }),
      code: t('trix_code', { defaultValue: 'Code' }),
      heading1: t('trix_heading', { defaultValue: 'Heading' }),
      indent: t('trix_indent', { defaultValue: 'Increase Level' }),
      italic: t('trix_italic', { defaultValue: 'Italic' }),
      link: t('trix_link', { defaultValue: 'Link' }),
      numbers: t('trix_numbers', { defaultValue: 'Numbers' }),
      outdent: t('trix_outdent', { defaultValue: 'Decrease Level' }),
      quote: t('trix_quote', { defaultValue: 'Quote' }),
      redo: t('trix_redo', { defaultValue: 'Redo' }),
      strike: t('trix_strike', { defaultValue: 'Strikethrough' }),
      undo: t('trix_undo', { defaultValue: 'Undo' }),
      unlink: t('trix_unlink', { defaultValue: 'Unlink' }),
      urlPlaceholder: t('trix_url_placeholder', { defaultValue: 'Enter a URL…' }),
      captionPlaceholder: t('trix_caption_placeholder', { defaultValue: 'Add a caption…' }),
    })
  }, [t])

  const currentValue =
    value === null || value === undefined ? "" : String(value)

  // The editor lives outside React and outlasts the render that built it. It
  // reports an edit through the `onChange` of the latest render (a Repeater
  // row hands a new one on every render, built on that render's rows), and a
  // rebuilt editor starts from the latest value; later values reach it through
  // the sync effect below.
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const currentValueRef = useRef(currentValue)
  currentValueRef.current = currentValue

  const extras = field as Record<string, unknown>
  const readonly = !!field.readonly
  const toolbarSize = extras.toolbarSize as string | undefined
  const withFiles = !!extras.withFiles

  // Build the Trix editor, again only when its configuration changes. The
  // cleanup tears it down, so a rebuild (and StrictMode's second run of the
  // effect) starts from an empty container.
  useEffect(() => {
    const container = containerRef.current
    if (!container) return

    const initialValue = currentValueRef.current
    const input = document.createElement("input")
    input.id = inputId
    input.type = "hidden"
    input.value = initialValue
    container.appendChild(input)
    hiddenInputRef.current = input
    lastPropValue.current = initialValue
    internalUpdate.current = false

    const editor = document.createElement("trix-editor")
    editor.setAttribute("input", inputId)
    editor.classList.add("trix-content")
    if (readonly) {
      editor.setAttribute("contenteditable", "false")
    }
    container.appendChild(editor)
    editorRef.current = editor

    const handleChange = () => {
      internalUpdate.current = true
      onChangeRef.current(input.value)
    }
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
      if (toolbarSize) {
        const tb = (editor as HTMLElement).previousElementSibling as HTMLElement
        if (tb) {
          tb.classList.add("martis-trix-toolbar-" + toolbarSize)
        }
      }

      // Replace Trix's native `title` tooltips with the package-wide
      // `data-pr-tooltip` convention so they render through the global
      // PrimeReact Tooltip (consistent look & feel with clear buttons,
      // row actions, etc.).
      if (toolbar) {
        toolbar.querySelectorAll<HTMLElement>("button[title]").forEach((btn) => {
          const tip = btn.getAttribute("title")
          if (tip) {
            btn.setAttribute("data-pr-tooltip", tip)
            btn.setAttribute("data-pr-position", "top")
            btn.removeAttribute("title")
          }
        })
      }

    })

    // Handle file attachments
    editor.addEventListener(
      "trix-attachment-add",
      ((event: Event) => {
        const attachment = (
          event as unknown as {
            attachment: {
              file: File | null
              remove: () => void
              setUploadProgress: (progress: number) => void
              setAttributes: (attrs: Record<string, string | number>) => void
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

    return () => {
      editor.removeEventListener("trix-change", handleChange)
      // The wrapper holds only what this effect added: the hidden input, the
      // editor and the toolbar Trix inserts before it.
      container.replaceChildren()
      editorRef.current = null
      hiddenInputRef.current = null
    }
  }, [inputId, readonly, toolbarSize, withFiles])

  // Sync external value changes into the editor (record loading on edit)
  useEffect(() => {
    if (!editorRef.current) return

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
