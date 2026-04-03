import { useToast } from '@/contexts/ToastContext'
import { X, CheckCircle, WarningCircle, Warning, Info } from '@phosphor-icons/react'

const icons = {
  success: CheckCircle,
  error: WarningCircle,
  warning: Warning,
  info: Info,
}

const styles = {
  success: 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300',
  error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300',
  warning: 'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300',
}

export function ToastContainer() {
  const { toasts, removeToast } = useToast()

  if (toasts.length === 0) return null

  return (
    <div
      aria-live="polite"
      className="fixed bottom-4 right-4 z-50 flex flex-col gap-2"
    >
      {toasts.map((toast) => {
        const Icon = icons[toast.type]
        return (
          <div
            key={toast.id}
            role="alert"
            className={`flex min-w-64 items-start gap-3 rounded-lg border p-3 shadow-md ${styles[toast.type]}`}
          >
            <Icon size={16} weight="fill" className="mt-0.5 shrink-0" />
            <span className="flex-1 text-sm">{toast.message}</span>
            <button
              onClick={() => removeToast(toast.id)}
              className="shrink-0 opacity-70 hover:opacity-100"
              aria-label="Fechar"
            >
              <X size={14} weight="bold" />
            </button>
          </div>
        )
      })}
    </div>
  )
}
