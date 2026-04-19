import { XIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'

interface ClearButtonProps {
  /** Show the button only when this is true (typically: nullable && hasValue && !readonly). */
  visible: boolean
  /** Click handler — should clear the field value. */
  onClick: (e: React.MouseEvent) => void
  /** Optional tooltip text override. Defaults to t('messages:clear'). */
  tooltip?: string
  /** Icon size in px. Defaults to 12. */
  size?: number
  /** Optional inline style overrides for absolute positioning inside an input wrapper. */
  style?: React.CSSProperties
  /** Optional className override (still merged with martis-clear-btn). */
  className?: string
}

/**
 * Standardized "clear" button for all Martis form fields.
 * - Always red (#ef4444)
 * - Always has a tooltip (defaults to "Clear")
 * - Renders nothing when `visible` is false
 *
 * Usage:
 *   <ClearButton visible={field.nullable && hasValue && !field.readonly} onClick={handleClear} />
 */
export function ClearButton({
  visible,
  onClick,
  tooltip,
  size = 12,
  style,
  className,
}: ClearButtonProps) {
  const { t } = useTranslation('messages')

  if (!visible) return null

  const tip = tooltip ?? t('clear', { defaultValue: 'Clear' })

  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        e.preventDefault()
        onClick(e)
      }}
      className={`martis-clear-btn ${className ?? ''}`.trim()}
      style={style}
      data-pr-tooltip={tip}
      data-pr-position="top"
      aria-label={tip}
    >
      <XIcon size={size} weight="bold" />
    </button>
  )
}
