import {
  createContext,
  useContext,
  useRef,
  useCallback,
  type MouseEvent as ReactMouseEvent,
  type ReactNode,
} from "react"
import { Toast } from "primereact/toast"
import { CheckIcon, InfoIcon, WarningIcon, XIcon } from "@phosphor-icons/react"
import { useTranslation } from "react-i18next"
import { config } from "@/lib/config"

type ToastType = "success" | "error" | "warning" | "info"
type Severity = "success" | "error" | "warn" | "info"

// PrimeReact's ContentProps type only declares `message`, but the runtime
// callback also receives `onClose` and `onClick`. Capture both so the close
// button can dismiss only this toast instead of clearing the whole stack.
interface ToastContentArgs {
  message: { summary?: string; detail?: string }
  onClose?: (event: ReactMouseEvent) => void
  onClick?: (event: ReactMouseEvent) => void
}

interface ToastContextValue {
  addToast: (type: ToastType, message: string) => void
  toastRef: React.RefObject<Toast | null>
}

const ToastContext = createContext<ToastContextValue | null>(null)

const severityMap: Record<ToastType, Severity> = {
  success: "success",
  error: "error",
  warning: "warn",
  info: "info",
}

const severityToken: Record<ToastType, string> = {
  success: "var(--martis-success)",
  error: "var(--martis-danger)",
  warning: "var(--martis-warning)",
  info: "var(--martis-info)",
}

const severityTitleKey: Record<ToastType, string> = {
  success: "toast_success",
  error: "toast_error",
  warning: "toast_warning",
  info: "toast_info",
}

function SeverityIcon({ type }: { type: ToastType }) {
  const props = { size: 14, weight: "bold" as const }
  switch (type) {
    case "success": return <CheckIcon {...props} />
    case "error":   return <XIcon {...props} />
    case "warning": return <WarningIcon {...props} />
    case "info":    return <InfoIcon {...props} />
  }
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const toastRef = useRef<Toast>(null)
  const { t } = useTranslation("messages")

  const addToast = useCallback(
    (type: ToastType, message: string) => {
      const life = 5000
      toastRef.current?.show({
        severity: severityMap[type] ?? "info",
        life,
        sticky: false,
        closable: true,
        content: (args) => {
          const { onClose } = args as ToastContentArgs
          return (
            <div
              className="martis-toast-body"
              style={{ display: "flex", alignItems: "flex-start", gap: 10, flex: 1, width: "100%" }}
            >
              <div className="martis-toast-icon" style={{ background: severityToken[type] }}>
                <SeverityIcon type={type} />
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div className="martis-toast-title">{t(severityTitleKey[type])}</div>
                <div className="martis-toast-message">{message}</div>
              </div>
              <button
                type="button"
                className="martis-toast-close"
                aria-label={t("close")}
                onClick={(e) => onClose?.(e)}
              >
                <XIcon size={14} weight="bold" />
              </button>
            </div>
          )
        },
      })

      // Safety-net: force-clear ALL messages after life + 1s in case
      // PrimeReact's internal timer doesn't fire (CSS transition edge-case).
      setTimeout(() => {
        toastRef.current?.clear()
      }, life + 1000)
    },
    [t],
  )

  const position = config.toast?.position ?? "bottom-right"

  return (
    <ToastContext.Provider value={{ addToast, toastRef }}>
      {children}
      <Toast ref={toastRef} position={position} baseZIndex={50000} />
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error("useToast must be used within ToastProvider")
  return ctx
}

/** No-op fallback for field components rendered outside ToastProvider (e.g. tests). */
const noopToast: ToastContextValue = {
  addToast: () => {},
  toastRef: { current: null },
}

/**
 * Safe variant of useToast that returns a no-op when used outside ToastProvider.
 * Use this in field components that need toast but must also work in tests.
 */
export function useToastSafe(): ToastContextValue {
  const ctx = useContext(ToastContext)
  return ctx ?? noopToast
}
