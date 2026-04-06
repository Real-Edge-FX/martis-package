import {
  createContext,
  useContext,
  useRef,
  useCallback,
  type ReactNode,
} from "react"
import { Toast } from "primereact/toast"
import { config } from "@/lib/config"

type Severity = "success" | "error" | "warn" | "info"

export interface ToastAction {
  label: string
  onClick: () => void
}

interface ToastContextValue {
  addToast: (type: "success" | "error" | "warning" | "info", message: string, action?: ToastAction) => void
  toastRef: React.RefObject<Toast | null>
}

const ToastContext = createContext<ToastContextValue | null>(null)

const severityMap: Record<string, Severity> = {
  success: "success",
  error: "error",
  warning: "warn",
  info: "info",
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const toastRef = useRef<Toast>(null)

  const addToast = useCallback(
    (type: "success" | "error" | "warning" | "info", message: string, action?: ToastAction) => {
      const life = action ? 8000 : 5000
      toastRef.current?.show({
        severity: severityMap[type] ?? "info",
        summary: type.charAt(0).toUpperCase() + type.slice(1),
        detail: action ? (
          <div className="flex items-center gap-3">
            <span className="flex-1">{message}</span>
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); action.onClick() }}
              className="shrink-0 rounded-md px-3 py-1 text-xs font-semibold transition-colors"
              style={{
                backgroundColor: 'var(--martis-accent)',
                color: '#fff',
              }}
            >
              {action.label}
            </button>
          </div>
        ) : message,
        life,
        sticky: false,
        closable: true,
      })

      // Safety-net: force-clear ALL messages after life + 1s in case
      // PrimeReact's internal timer doesn't fire (CSS transition edge-case).
      setTimeout(() => {
        toastRef.current?.clear()
      }, life + 1000)
    },
    [],
  )

  const position = config.toast?.position ?? "bottom-right"

  return (
    <ToastContext.Provider value={{ addToast, toastRef }}>
      {children}
      <Toast ref={toastRef} position={position} baseZIndex={9999} />
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
