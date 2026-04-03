import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { layoutRegistry } from '@/lib/layoutRegistry'
import { registerDefaultFields, FieldDisplay } from '@/components/fields'
import type { FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from '@/components/fields/types'

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(() => {
  registerDefaultFields()
})

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const textField: FieldDefinition = {
  attribute: 'name',
  label: 'Name',
  type: 'text',
  nullable: false,
  readonly: false,
  required: true,
  sortable: true,
  searchable: true,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: ['required'],
}

const statusField: FieldDefinition = {
  attribute: 'status',
  label: 'Status',
  type: 'text',
  nullable: false,
  readonly: false,
  required: false,
  sortable: false,
  searchable: false,
  showOnIndex: true,
  showOnDetail: true,
  showOnForms: true,
  rules: [],
  component: 'status-badge',
}

// ---------------------------------------------------------------------------
// ComponentRegistry — basic operations
// ---------------------------------------------------------------------------

describe('ComponentRegistry', () => {
  it('registers a component by arbitrary key', () => {
    const Comp = vi.fn(() => null)
    componentRegistry.register('test:bloco9-key', Comp as never)
    expect(componentRegistry.has('test:bloco9-key')).toBe(true)
  })

  it('registers and resolves global type override', () => {
    const CustomText = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>
    componentRegistry.registerFieldDisplay('text', CustomText)
    const fallback = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>

    const resolved = componentRegistry.resolveDisplay('text', 'name', undefined, null, fallback)
    expect(resolved).toBe(CustomText)
  })

  it('registers and resolves per-resource field override', () => {
    const Fallback = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>
    const CustomComp = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>

    componentRegistry.registerResourceFieldDisplay('posts', 'status', CustomComp)

    const resolved = componentRegistry.resolveDisplay('text', 'status', 'posts', null, Fallback)
    expect(resolved).toBe(CustomComp)
  })

  it('per-resource override does not affect other resources', () => {
    const Fallback = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>
    const PostsCustom = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>

    componentRegistry.registerResourceFieldDisplay('posts', 'status', PostsCustom)

    // "users" resource should NOT get the posts override
    const resolved = componentRegistry.resolveDisplay('text', 'status', 'users', null, Fallback)
    expect(resolved).not.toBe(PostsCustom)
  })

  it('explicit component key takes highest priority', () => {
    const Fallback = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>
    const ExplicitComp = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>
    const ResourceComp = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>

    componentRegistry.register('status-badge', ExplicitComp as never)
    componentRegistry.registerResourceFieldDisplay('posts', 'status', ResourceComp)

    // Explicit key wins over resource-level override
    const resolved = componentRegistry.resolveDisplay('text', 'status', 'posts', 'status-badge', Fallback)
    expect(resolved).toBe(ExplicitComp)
  })

  it('falls back to default when no override registered for unknown type', () => {
    const FallbackComp = vi.fn(() => null) as unknown as React.ComponentType<FieldDisplayProps>

    // 'custom-unknown-type' has no default registered — all 4 tiers miss → fallback
    const resolved = componentRegistry.resolveDisplay(
      'custom-unknown-type',
      'some-field',
      'some-resource',
      null,
      FallbackComp,
    )
    expect(resolved).toBe(FallbackComp)
  })

  it('resolveInput follows same priority chain', () => {
    const Fallback = vi.fn(() => null) as unknown as React.ComponentType<FieldInputProps>
    const CustomInput = vi.fn(() => null) as unknown as React.ComponentType<FieldInputProps>

    componentRegistry.registerResourceFieldInput('users', 'name', CustomInput)

    const resolved = componentRegistry.resolveInput('text', 'name', 'users', null, Fallback)
    expect(resolved).toBe(CustomInput)
  })
})

// ---------------------------------------------------------------------------
// LayoutRegistry
// ---------------------------------------------------------------------------

describe('LayoutRegistry', () => {
  it('registers and resolves custom layout for a resource', () => {
    const DefaultLayout = vi.fn(({ children }) => <div>{children}</div>)
    const CustomLayout = vi.fn(({ children }) => <section>{children}</section>)

    layoutRegistry.register('users', CustomLayout as never)

    const resolved = layoutRegistry.resolve('users', DefaultLayout as never)
    expect(resolved).toBe(CustomLayout)
  })

  it('falls back to default layout for unknown resource', () => {
    const DefaultLayout = vi.fn(({ children }) => <div>{children}</div>)

    const resolved = layoutRegistry.resolve('unknown-resource-xyz', DefaultLayout as never)
    expect(resolved).toBe(DefaultLayout)
  })

  it('layout override for one resource does not affect another', () => {
    const DefaultLayout = vi.fn(({ children }) => <div>{children}</div>)
    const UsersLayout = vi.fn(({ children }) => <section>{children}</section>)

    layoutRegistry.register('users', UsersLayout as never)

    const resolved = layoutRegistry.resolve('posts', DefaultLayout as never)
    expect(resolved).toBe(DefaultLayout)
  })

  it('has() returns true for registered resource', () => {
    const Layout = vi.fn(() => null)
    layoutRegistry.register('categories', Layout as never)
    expect(layoutRegistry.has('categories')).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// FieldDisplay — integration with componentRegistry
// ---------------------------------------------------------------------------

describe('FieldDisplay with override', () => {
  it('renders custom display component when explicit key is registered', () => {
    const CustomDisplay = ({ value }: FieldDisplayProps) => (
      <span data-testid="custom-status">{String(value)}</span>
    )
    componentRegistry.register('status-badge', CustomDisplay as never)

    render(<FieldDisplay field={statusField} value="active" />)

    expect(screen.getByTestId('custom-status')).toBeTruthy()
    expect(screen.getByTestId('custom-status').textContent).toBe('active')
  })

  it('renders per-resource display component when registered', () => {
    const ResourceDisplay = ({ value }: FieldDisplayProps) => (
      <em data-testid="resource-name">{String(value)}</em>
    )
    componentRegistry.registerResourceFieldDisplay('users', 'name', ResourceDisplay)

    render(<FieldDisplay field={textField} value="Alice" resourceKey="users" />)

    expect(screen.getByTestId('resource-name').textContent).toBe('Alice')
  })
})
