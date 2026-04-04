import { useToast } from '@/contexts/ToastContext'
import { config } from '@/lib/config'
import { CheckCircle, XCircle, Warning, Info, X } from '@phosphor-icons/react'
import type { ComponentType } from 'react'
import type { IconProps } from '@phosphor-icons/react'

const iconMap: Record<string, ComponentType<IconProps>> = {
  success: CheckCircle,
  error: XCircle,
  warning: Warning,
  info: Info,
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
      {toasts.map((toast) => {
        const Icon = iconMap[toast.type]
        return (
          <div
            key={toast.id}
            role="alert"
            className={`martis-toast martis-toast-${toast.type}`}
          >
            {Icon && (
              <Icon
                size={16}
                className="shrink-0"
                style={{ color: iconColor[toast.type] }}
              />
            )}
            <span className="flex-1 text-sm">{toast.message}</span>
            <button
              onClick={() => removeToast(toast.id)}
              className="shrink-0 martis-text-muted hover:opacity-100 cursor-pointer bg-transparent border-0 p-0"
              style={{ opacity: 0.7 }}
              aria-label="Close"
            >
              <X size={12} />
            </button>
          </div>
        )
      })}
    </div>
  )
}
