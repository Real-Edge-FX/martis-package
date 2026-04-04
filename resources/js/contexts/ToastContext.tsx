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

interface ToastContextValue {
  addToast: (type: "success" | "error" | "warning" | "info", message: string) => void
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
    (type: "success" | "error" | "warning" | "info", message: string) => {
      toastRef.current?.show({
        severity: severityMap[type] ?? "info",
        summary: type.charAt(0).toUpperCase() + type.slice(1),
        detail: message,
        life: 5000,
        sticky: false,
        closable: true,
      })
    },
    [],
  )

  const position = config.toast?.position ?? "bottom-right"

  return (
    <ToastContext.Provider value={{ addToast, toastRef }}>
      {children}
      <Toast ref={toastRef} position={position} />
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error("useToast must be used within ToastProvider")
  return ctx
}
