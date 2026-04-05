import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { registry } from '@/lib/registry'
import { registerDefaultFields } from '@/components/fields'
import { FieldDisplay, FieldInput } from '@/components/fields'
import { Table } from '@/components/Table'
import { Pagination } from '@/components/Pagination'
import { DeleteModal } from '@/components/DeleteModal'
import type { FieldDefinition, ResourceRecord } from '@/types'

beforeEach(() => {
  registerDefaultFields()
})

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------

const textField: FieldDefinition = {
  attribute: 'name', label: 'Name', type: 'text',
  nullable: false, readonly: false, required: true,
  sortable: true, searchable: true,
  showOnIndex: true, showOnDetail: true, showOnForms: true,
  rules: ['required'],
}

const boolField: FieldDefinition = {
  attribute: 'active', label: 'Active', type: 'boolean',
  nullable: false, readonly: false, required: false,
  sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true,
  rules: [],
}

const selectField: FieldDefinition = {
  attribute: 'status', label: 'Status', type: 'select',
  nullable: true, readonly: false, required: false,
  sortable: false, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: true,
  rules: [],
  options: [
    { value: 'draft', label: 'Rascunho' },
    { value: 'published', label: 'Publicado' },
  ],
}

const dateField: FieldDefinition = {
  attribute: 'created_at', label: 'Created At', type: 'date',
  nullable: true, readonly: false, required: false,
  sortable: true, searchable: false,
  showOnIndex: true, showOnDetail: true, showOnForms: false,
  rules: [],
}

// -------------------------------------------------------------------------
// Registry
// -------------------------------------------------------------------------

describe('ComponentRegistry', () => {
  it('registers and resolves components', () => {
    const Dummy = () => null
    registry.register('test:dummy', Dummy)
    expect(registry.get('test:dummy')).toBe(Dummy)
  })

  it('resolve() returns fallback when key not registered', () => {
    const Fallback = () => null
    expect(registry.resolve('test:nonexistent-xyz', Fallback)).toBe(Fallback)
  })

  it('resolve() returns override when registered', () => {
    const Override = () => null
    registry.register('test:override-key', Override)
    const Fallback = () => null
    expect(registry.resolve('test:override-key', Fallback)).toBe(Override)
  })

  it('has() returns true for registered keys', () => {
    const C = () => null
    registry.register('test:has-check', C)
    expect(registry.has('test:has-check')).toBe(true)
  })
})

// -------------------------------------------------------------------------
// FieldDisplay
// -------------------------------------------------------------------------

describe('FieldDisplay', () => {
  it('renders text value', () => {
    render(<FieldDisplay field={textField} value="Hello" />)
    expect(screen.getByText('Hello')).toBeTruthy()
  })

  it('renders em dash for null text', () => {
    render(<FieldDisplay field={textField} value={null} />)
    expect(screen.getByText('—')).toBeTruthy()
  })

  it('renders boolean true as Yes', () => {
    render(<FieldDisplay field={boolField} value={true} />)
    expect(screen.getByText('Yes')).toBeTruthy()
  })

  it('renders boolean false as No', () => {
    render(<FieldDisplay field={boolField} value={false} />)
    expect(screen.getByText('No')).toBeTruthy()
  })

  it('renders select option label', () => {
    render(<FieldDisplay field={selectField} value="draft" />)
    expect(screen.getByText('Rascunho')).toBeTruthy()
  })

  it('renders em dash for null date', () => {
    render(<FieldDisplay field={dateField} value={null} />)
    expect(screen.getByText('—')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// FieldInput
// -------------------------------------------------------------------------

describe('FieldInput', () => {
  it('renders text input with value', () => {
    render(<FieldInput field={textField} value="test" onChange={vi.fn()} />)
    const input = screen.getByRole('textbox') as HTMLInputElement
    expect(input.value).toBe('test')
  })

  it('calls onChange when text input changes', () => {
    const onChange = vi.fn()
    render(<FieldInput field={textField} value="" onChange={onChange} />)
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'new value' } })
    expect(onChange).toHaveBeenCalledWith('new value')
  })

  it('renders boolean toggle', () => {
    render(<FieldInput field={boolField} value={false} onChange={vi.fn()} />)
    expect(screen.getByRole('switch')).toBeTruthy()
  })

  it('calls onChange when boolean toggle clicked', () => {
    const onChange = vi.fn()
    render(<FieldInput field={boolField} value={false} onChange={onChange} />)
    fireEvent.click(screen.getByRole('switch'))
    expect(onChange).toHaveBeenCalledWith(true)
  })

  it('renders select with options', () => {
    render(<FieldInput field={selectField} value="draft" onChange={vi.fn()} />)
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('draft')
  })

  it('displays validation error', () => {
    render(<FieldInput field={textField} value="" onChange={vi.fn()} error="Campo obrigatório" />)
    expect(screen.getByText('Campo obrigatório')).toBeTruthy()
  })
})

// -------------------------------------------------------------------------
// Table
// -------------------------------------------------------------------------

describe('Table', () => {
  const columns = [{ field: textField }, { field: boolField }]
  const rows: ResourceRecord[] = [
    { id: 1, name: 'Alice', active: true, _resource: { uriKey: 'users', label: 'Users', singularLabel: 'User', softDeletes: false, group: null } },
    { id: 2, name: 'Bob', active: false, _resource: { uriKey: 'users', label: 'Users', singularLabel: 'User', softDeletes: false, group: null } },
  ]

  it('renders column headers', () => {
    render(
      <Table columns={columns} rows={rows}
        sortBy={null} sortDir="asc" onSort={vi.fn()}
        selectedIds={new Set()} onToggleSelect={vi.fn()} onToggleAll={vi.fn()}
      />
    )
    expect(screen.getByText('Name')).toBeTruthy()
    expect(screen.getByText('Active')).toBeTruthy()
  })

  it('renders row data', () => {
    render(
      <Table columns={columns} rows={rows}
        sortBy={null} sortDir="asc" onSort={vi.fn()}
        selectedIds={new Set()} onToggleSelect={vi.fn()} onToggleAll={vi.fn()}
      />
    )
    expect(screen.getByText('Alice')).toBeTruthy()
    expect(screen.getByText('Bob')).toBeTruthy()
  })

  it('renders empty state when no rows', () => {
    render(
      <Table columns={columns} rows={[]}
        sortBy={null} sortDir="asc" onSort={vi.fn()}
        selectedIds={new Set()} onToggleSelect={vi.fn()} onToggleAll={vi.fn()}
      />
    )
    expect(screen.getByText('No records found.')).toBeTruthy()
  })

  it('calls onSort when sortable column header clicked', () => {
    const onSort = vi.fn()
    render(
      <Table columns={columns} rows={rows}
        sortBy={null} sortDir="asc" onSort={onSort}
        selectedIds={new Set()} onToggleSelect={vi.fn()} onToggleAll={vi.fn()}
      />
    )
    fireEvent.click(screen.getByText('Name'))
    expect(onSort).toHaveBeenCalledWith('name')
  })

  it('calls onToggleSelect when row checkbox clicked', () => {
    const onToggleSelect = vi.fn()
    render(
      <Table columns={columns} rows={rows}
        sortBy={null} sortDir="asc" onSort={vi.fn()}
        selectedIds={new Set()} onToggleSelect={onToggleSelect} onToggleAll={vi.fn()}
        selectable={true}
      />
    )
    const checkboxes = screen.getAllByRole('checkbox')
    fireEvent.click(checkboxes[1])
    expect(onToggleSelect).toHaveBeenCalledWith(1)
  })
})

// -------------------------------------------------------------------------
// Pagination
// -------------------------------------------------------------------------

describe('Pagination', () => {
  it('renders page buttons', () => {
    render(
      <Pagination currentPage={1} lastPage={3} total={30}
        perPage={10} from={1} to={10} onPageChange={vi.fn()}
      />
    )
    // Use getAllByRole to handle multiple elements with same text
    const buttons = screen.getAllByRole('button')
    const labels = buttons.map((b) => b.textContent?.trim())
    expect(labels).toContain('1')
    expect(labels).toContain('2')
    expect(labels).toContain('3')
  })

  it('does not render when only one page', () => {
    const { container } = render(
      <Pagination currentPage={1} lastPage={1} total={5}
        perPage={10} from={1} to={5} onPageChange={vi.fn()}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('calls onPageChange when page button clicked', () => {
    const onPageChange = vi.fn()
    render(
      <Pagination currentPage={1} lastPage={3} total={30}
        perPage={10} from={1} to={10} onPageChange={onPageChange}
      />
    )
    const buttons = screen.getAllByRole('button')
    const page2 = buttons.find((b) => b.textContent?.trim() === '2')
    expect(page2).toBeTruthy()
    fireEvent.click(page2!)
    expect(onPageChange).toHaveBeenCalledWith(2)
  })
})

// -------------------------------------------------------------------------
// DeleteModal
// -------------------------------------------------------------------------

describe('DeleteModal', () => {
  it('does not render when closed', () => {
    const { container } = render(
      <DeleteModal open={false} resourceLabel="User" isSoftDelete={false}
        onConfirm={vi.fn()} onCancel={vi.fn()}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('renders when open', () => {
    render(
      <DeleteModal open={true} resourceLabel="User" isSoftDelete={false}
        onConfirm={vi.fn()} onCancel={vi.fn()}
      />
    )
    expect(screen.getByRole('dialog')).toBeTruthy()
    expect(screen.getByText(/Delete User/)).toBeTruthy()
  })

  it('shows "Archive" for soft delete', () => {
    render(
      <DeleteModal open={true} resourceLabel="Post" isSoftDelete={true}
        onConfirm={vi.fn()} onCancel={vi.fn()}
      />
    )
    expect(screen.getByText(/Archive Post/)).toBeTruthy()
  })

  it('calls onCancel when Cancel clicked', () => {
    const onCancel = vi.fn()
    render(
      <DeleteModal open={true} resourceLabel="User" isSoftDelete={false}
        onConfirm={vi.fn()} onCancel={onCancel}
      />
    )
    fireEvent.click(screen.getByText('Cancel'))
    expect(onCancel).toHaveBeenCalled()
  })
})
