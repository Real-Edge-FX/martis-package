import { useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { Dropdown } from 'primereact/dropdown'
import type { DropdownProps } from 'primereact/dropdown'
import { SearchIcon } from 'primereact/icons/search'
import { useTranslation } from 'react-i18next'
import { dropdownClearIconPt } from './dropdownHelpers'
import { remoteOptionsEndpoint, useRemoteSelectOptions } from '@/hooks/useRemoteSelectOptions'

export function SelectFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const opt = field.options?.find((o) => String(o.value) === String(value))
  // PHP `Select::displayUsingLabels()` (default `true`) controls whether
  // the index/detail cell renders the option label or the raw stored
  // value. Falling back to the original label-resolution path when the
  // flag is missing keeps prior payloads working.
  const displayLabels = (field as Record<string, unknown>).displayLabels !== false
  const rendered = displayLabels && opt ? opt.label : String(value)
  return (
    <span className="martis-badge martis-badge-neutral">
      {rendered}
    </span>
  )
}

/**
 * What PrimeReact hands to `filterTemplate` at runtime (the subset we use).
 * Its typings only declare `{ filterOptions }`, but `createFilter()` passes
 * the container className, the icon className and the keyboard handler too.
 */
interface DropdownFilterTemplateOptions {
  className: string
  filterIconClassName: string
  filterInputKeyDown?: (event: KeyboardEvent<HTMLInputElement>) => void
}

export function SelectFieldInput({ field, value, onChange, error, resourceKey, toolKey, context }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const staticOptions = field.options?.map((o) => ({ label: o.label, value: String(o.value) })) ?? []
  // Pass `null` (not '') when empty so PrimeReact's own `value != null` guard
  // hides the clear (X) icon on an empty select — an empty select has nothing
  // to clear. Coercing to '' made `showClear` fire on the placeholder state.
  // Guard the rare case of a real option whose value is literally '': only
  // treat '' as "empty" when no such option exists, so a genuinely-selected
  // empty-string option still highlights.
  const hasEmptyOption = staticOptions.some((o) => o.value === '')
  const isEmpty = value === null || value === undefined || (value === '' && !hasEmptyOption)
  const currentValue = isEmpty ? null : String(value)
  const clearTip = t('clear', { defaultValue: 'Clear' })
  const selectPlaceholder = field.placeholder ?? t('select', { defaultValue: 'Select…' })
  // PHP `Select::searchableOptions()` / `allowCustomValues()` /
  // `searchOptionsUsing()` (v1.37.0). `field.searchable` is the column-search
  // flag and must NOT drive the box.
  const customValues = field.allowCustomValues === true
  // Remote search needs a scope to derive its endpoint from; without one the
  // select degrades to local filtering, the way `dependsOn` degrades offline.
  const endpoint = field.remoteOptionsSearch === true
    ? remoteOptionsEndpoint(field.attribute, { resourceKey, toolKey, context })
    : null
  const remote = endpoint !== null
  const searchable = field.searchableOptions === true || remote

  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const remoteState = useRemoteSelectOptions({ endpoint, open, term })
  const options = remote && remoteState.options !== null ? remoteState.options : staticOptions

  // Opt into the compact filter-dropdown look (used by native resource filters)
  // via `field.variant === 'filter'`, and allow an extra passthrough className.
  // Applied as a prop so PrimeReact re-applies it on every re-render (an
  // imperative classList.add is dropped when PrimeReact rewrites the class).
  const dropdownClass = [
    'w-full',
    field.variant === 'filter' ? 'martis-filter-dropdown' : '',
    field.className ?? '',
  ].filter(Boolean).join(' ')

  // Remote mode renders its own search box. PrimeReact's built-in filter
  // input would ALSO filter the server's results locally (label/value
  // "contains"), hiding matches the server made on other grounds
  // (accent-insensitive, fuzzy, a hidden column). Not calling PrimeReact's
  // filter callbacks keeps its internal filter state empty, so every
  // option we pass is shown; `filterInputKeyDown` keeps arrow-key
  // navigation from the box into the list.
  const remoteFilterTemplate = (opts: DropdownFilterTemplateOptions) => (
    <div className={opts.className}>
      <input
        type="text"
        autoComplete="off"
        autoFocus={!customValues}
        className="p-dropdown-filter p-inputtext p-component"
        placeholder={t('search')}
        value={term}
        onChange={(e) => setTerm(e.target.value)}
        onKeyDown={opts.filterInputKeyDown}
        data-testid={`select-remote-search-${field.attribute}`}
      />
      <SearchIcon className={opts.filterIconClassName} />
    </div>
  )

  // A stored value that is not in the (partial) remote list would otherwise
  // render as the placeholder, as if nothing were selected.
  const remoteValueTemplate = (option: { label: string } | null) =>
    option ? option.label : (currentValue ?? selectPlaceholder)

  const remoteEmptyMessage = remoteState.error
    ? t('options_load_error')
    : term.trim() !== '' ? t('no_results_found') : t('no_options')

  const remoteProps = remote
    ? {
        filterTemplate: remoteFilterTemplate as unknown as DropdownProps['filterTemplate'],
        valueTemplate: remoteValueTemplate,
        loading: remoteState.loading,
        emptyMessage: remoteEmptyMessage,
        onShow: () => setOpen(true),
        onHide: () => { setOpen(false); setTerm('') },
      }
    : {}

  return (
    <div className="flex flex-col gap-1">
      <Dropdown
        inputId={field.attribute}
        name={field.attribute}
        value={currentValue}
        options={options}
        onChange={(e) => onChange(e.value as string)}
        disabled={field.readonly}
        invalid={!!error}
        placeholder={selectPlaceholder}
        showClear={field.nullable}
        editable={customValues}
        filter={searchable}
        filterBy="label,value"
        filterPlaceholder={t('search')}
        // With custom values on, the user types in the control itself, so
        // the panel must not steal the focus when it opens.
        filterInputAutoFocus={searchable && !remote && !customValues}
        resetFilterOnHide
        emptyFilterMessage={t('no_results_found')}
        emptyMessage={t('no_options')}
        {...remoteProps}
        pt={{
          clearIcon: dropdownClearIconPt(clearTip),
        }}
        className={dropdownClass}
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
