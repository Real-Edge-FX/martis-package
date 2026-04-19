import { useTranslation } from 'react-i18next'
import { CheckCircleIcon, CircleIcon } from '@phosphor-icons/react'

export interface PasswordRule {
  key: string
  label: string
  test: (value: string) => boolean
}

export function defaultPasswordRules(t: (key: string) => string): PasswordRule[] {
  return [
    { key: 'min', label: t('password_rule_min'), test: (v) => v.length >= 8 },
    { key: 'upper', label: t('password_rule_upper'), test: (v) => /[A-Z]/.test(v) },
    { key: 'lower', label: t('password_rule_lower'), test: (v) => /[a-z]/.test(v) },
    { key: 'number', label: t('password_rule_number'), test: (v) => /[0-9]/.test(v) },
  ]
}

interface PasswordChecklistProps {
  value: string
  rules?: PasswordRule[]
}

export function PasswordChecklist({ value, rules }: PasswordChecklistProps) {
  const { t } = useTranslation('profile')
  const items = rules ?? defaultPasswordRules(t)

  return (
    <ul className="martis-password-checklist" role="list" aria-label={t('password_requirements')}>
      {items.map((rule) => {
        const met = rule.test(value)
        return (
          <li
            key={rule.key}
            className={`martis-password-checklist-item${met ? ' is-met' : ''}`}
            data-met={met}
          >
            <span className="martis-password-checklist-icon" aria-hidden="true">
              {met ? <CheckCircleIcon size={14} weight="fill" /> : <CircleIcon size={14} />}
            </span>
            <span>{rule.label}</span>
          </li>
        )
      })}
    </ul>
  )
}

export function allRulesMet(value: string, rules: PasswordRule[]): boolean {
  return rules.every((rule) => rule.test(value))
}
