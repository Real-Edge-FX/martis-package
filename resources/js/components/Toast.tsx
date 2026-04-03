import { useToast } from '@/contexts/ToastContext'
import { config } from '@/lib/config'

const iconMap = {
  success: 'pi-check-circle',
  error: 'pi-times-circle',
  warning: 'pi-exclamation-triangle',
  info: 'pi-info-circle',
}

const iconColor = {
  success: '#22c55e',
  error: '#ef4444',
  warning: '#f59e0b',
  info: '#3b82f6',
}

export function ToastContainer() {
  const { toasts, removeToast } = useToast()

  if (toasts.length === 0) return null

  const position = config.toast?.position ?? 'bottom-right'

  return (
    <div className={`martis-toast-container pos-${position}`} aria-live="polite">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          role="alert"
          className={`martis-toast martis-toast-${toast.type}`}
        >
          <i
            className={`pi ${iconMap[toast.type]} text-base shrink-0`}
            style={{ color: iconColor[toast.type] }}
          />
          <span className="flex-1 text-sm">{toast.message}</span>
          <button
            onClick={() => removeToast(toast.id)}
            className="shrink-0 martis-text-muted hover:opacity-100 cursor-pointer bg-transparent border-0 p-0"
            style={{ opacity: 0.7 }}
            aria-label="Close"
          >
            <i className="pi pi-times text-xs" />
          </button>
        </div>
      ))}
    </div>
  )
}
