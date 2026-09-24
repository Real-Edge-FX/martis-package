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

/** An option as the Dropdown receives it: the value as a string, the group kept. */
interface DropdownOption {
  label: string
  value: string
  group?: string
}

/** A group of options, the shape PrimeReact's Dropdown renders under a heading. */
interface DropdownOptionGroup {
  label: string
  items: DropdownOption[]
}

/**
 * PrimeReact's Dropdown groups only when every entry is a group. Options
 * that name a group (Nova's grouped options, v2.0.0) gather under it, in
 * the order the groups first appear; options without one form a leading
 * group whose empty heading `martis.css` hides. `null` when no option names
 * a group, so an ungrouped select renders exactly as before.
 *
 * A bucketed item drops the `group` it carried: PrimeReact renders ANY
 * entry whose own `.group` is truthy as a heading row
 * (`option.group && props.optionGroupLabel` in `createItem`), so leaving it
 * on the item itself would render every option as a second, phantom
 * heading alongside its real one.
 */
function groupDropdownOptions(options: DropdownOption[]): DropdownOptionGroup[] | null {
  if (!options.some((o) => o.group)) return null
  const ungrouped: DropdownOptionGroup = { label: '', items: [] }
  const groups: DropdownOptionGroup[] = []
  for (const option of options) {
    const item: DropdownOption = { label: option.label, value: option.value }
    if (!option.group) {
      ungrouped.items.push(item)
      continue
    }
    let group = groups.find((g) => g.label === option.group)
    if (!group) {
      group = { label: option.group, items: [] }
      groups.push(group)
    }
    group.items.push(item)
  }
  return ungrouped.items.length > 0 ? [ungrouped, ...groups] : groups
}

export function SelectFieldInput({ field, value, onChange, error, resourceKey, recordId, toolKey, context, repeaterRow }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const staticOptions: DropdownOption[] = field.options?.map((o) => ({
    label: o.label,
    value: String(o.value),
    ...(o.group ? { group: o.group } : {}),
  })) ?? []
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
    ? remoteOptionsEndpoint(field.attribute, { resourceKey, toolKey, context, recordId, repeaterRow })
    : null
  const remote = endpoint !== null
  const searchable = field.searchableOptions === true || remote

  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const remoteState = useRemoteSelectOptions({ endpoint, open, term })
  // Closed: the initial list plus the stored value, because PrimeReact hides
  // the clear icon (and resolves no label) when `options` lacks the value or
  // is empty. Open: exactly what the server returned.
  const remoteClosedOptions = remote && currentValue !== null && !staticOptions.some((o) => o.value === currentValue)
    ? [{ label: currentValue, value: currentValue }, ...staticOptions]
    : staticOptions
  const options = remote && open && remoteState.options !== null ? remoteState.options : remoteClosedOptions

  const optionGroups = groupDropdownOptions(options)
  const optionProps = optionGroups
    ? {
        options: optionGroups,
        optionGroupLabel: 'label',
        optionGroupChildren: 'items',
        optionGroupTemplate: (group: DropdownOptionGroup) => group.label || null,
      }
    : { options }

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
        {...optionProps}
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
