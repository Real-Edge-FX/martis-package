import { config, type PasswordRequirements } from '@/lib/config'
import type { FieldDefinition } from '@/types'

/**
 * The app's password policy, as the server describes it
 * (`window.MartisConfig.auth.passwordRequirements`, from
 * `Password::defaults()`), or null when the app's rule cannot be described.
 */
export function passwordRequirements(): PasswordRequirements | null {
  return config.auth?.passwordRequirements ?? null
}

/**
 * The new-password field of the profile and auth pages: the Password stack
 * of a resource form (strength meter, live checklist), with the checklist of
 * the app's policy. The server validates with the same policy and has the
 * last word: the checklist only guides.
 */
export function policyPasswordField(attribute: string, label: string): FieldDefinition {
  const requirements = passwordRequirements()

  return {
    attribute,
    label,
    type: 'password',
    nullable: false,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    strengthMeter: true,
    showRequirements: requirements !== null,
    ...(requirements !== null ? { requirements } : {}),
  } as unknown as FieldDefinition
}

/** The confirmation field paired with {@link policyPasswordField}: the live match indicator. */
export function confirmationField(attribute: string, confirms: string, label: string): FieldDefinition {
  return {
    attribute,
    label,
    type: 'password_confirmation',
    nullable: true,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    confirms,
  } as unknown as FieldDefinition
}
