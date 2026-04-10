import { describe, it, expect } from 'vitest'
// @testing-library/react available for component tests

// ---------------------------------------------------------------------------
// ActionMeta fixture factory
// ---------------------------------------------------------------------------

interface ActionMeta {
  uriKey: string
  name: string
  icon: string | null
  showIcon: boolean
  iconColor: string | null
  group: string | null
  destructive: boolean
  showOnIndex: boolean
  showOnDetail: boolean
  showInline: boolean
  executionMode: string
  standalone: boolean
  sole: boolean
  queued: boolean
  withConfirmation: boolean
  confirmText: string | null
  confirmButtonText: string | null
  cancelButtonText: string | null
  modalSize: string
  supportsDryRun: boolean
  customComponent: string | null
  customComponentProps: Record<string, unknown>
  logEvents: boolean
}

function makeAction(overrides: Partial<ActionMeta> = {}): ActionMeta {
  return {
    uriKey: 'test-action',
    name: 'Test Action',
    icon: null,
    showIcon: true,
    iconColor: null,
    group: null,
    destructive: false,
    showOnIndex: true,
    showOnDetail: true,
    showInline: false,
    executionMode: 'bulk',
    standalone: false,
    sole: false,
    queued: false,
    withConfirmation: true,
    confirmText: null,
    confirmButtonText: null,
    cancelButtonText: null,
    modalSize: 'md',
    supportsDryRun: false,
    customComponent: null,
    customComponentProps: {},
    logEvents: true,
    ...overrides,
  }
}

// ---------------------------------------------------------------------------
// ActionMeta serialization tests (mirrors backend jsonSerialize)
// ---------------------------------------------------------------------------

describe('ActionMeta type', () => {
  it('has all required fields for normal action', () => {
    const action = makeAction()
    expect(action.uriKey).toBe('test-action')
    expect(action.name).toBe('Test Action')
    expect(action.destructive).toBe(false)
    expect(action.showOnIndex).toBe(true)
    expect(action.showInline).toBe(false)
    expect(action.withConfirmation).toBe(true)
    expect(action.logEvents).toBe(true)
  })

  it('represents destructive action with correct metadata', () => {
    const action = makeAction({
      uriKey: 'archive-post',
      name: 'Archive Post',
      destructive: true,
      icon: 'archive-box',
    })
    expect(action.destructive).toBe(true)
    expect(action.icon).toBe('archive-box')
  })

  it('represents inline action', () => {
    const action = makeAction({
      showInline: true,
      icon: 'rocket-launch',
    })
    expect(action.showInline).toBe(true)
    expect(action.icon).toBe('rocket-launch')
  })

  it('represents standalone action', () => {
    const action = makeAction({
      standalone: true,
      executionMode: 'standalone',
    })
    expect(action.standalone).toBe(true)
    expect(action.executionMode).toBe('standalone')
  })

  it('represents sole action', () => {
    const action = makeAction({
      sole: true,
      executionMode: 'sole',
    })
    expect(action.sole).toBe(true)
    expect(action.executionMode).toBe('sole')
  })

  it('represents grouped action', () => {
    const action = makeAction({
      group: 'Export',
      icon: 'file-arrow-down',
    })
    expect(action.group).toBe('Export')
  })

  it('represents nested group (dot-notation)', () => {
    const action = makeAction({
      group: 'Notifications.Email',
    })
    expect(action.group).toBe('Notifications.Email')
  })
})

// ---------------------------------------------------------------------------
// Action visibility filtering (mirrors frontend logic)
// ---------------------------------------------------------------------------

describe('Action visibility filtering', () => {
  const actions = [
    makeAction({ uriKey: 'a1', name: 'Index Action', showOnIndex: true, showOnDetail: false, showInline: false }),
    makeAction({ uriKey: 'a2', name: 'Detail Action', showOnIndex: false, showOnDetail: true, showInline: false }),
    makeAction({ uriKey: 'a3', name: 'Inline Action', showOnIndex: false, showOnDetail: false, showInline: true }),
    makeAction({ uriKey: 'a4', name: 'Everywhere Action', showOnIndex: true, showOnDetail: true, showInline: true }),
  ]

  it('filters for index context', () => {
    const indexActions = actions.filter((a) => a.showOnIndex)
    expect(indexActions.map((a) => a.name)).toEqual(['Index Action', 'Everywhere Action'])
  })

  it('filters for detail context', () => {
    const detailActions = actions.filter((a) => a.showOnDetail)
    expect(detailActions.map((a) => a.name)).toEqual(['Detail Action', 'Everywhere Action'])
  })

  it('filters for inline context', () => {
    const inlineActions = actions.filter((a) => a.showInline)
    expect(inlineActions.map((a) => a.name)).toEqual(['Inline Action', 'Everywhere Action'])
  })
})

// ---------------------------------------------------------------------------
// Action group tree building (mirrors ActionDropdown logic)
// ---------------------------------------------------------------------------

interface ActionGroup {
  label: string
  children: Array<ActionMeta | ActionGroup>
}

function isActionGroup(item: ActionMeta | ActionGroup): item is ActionGroup {
  return 'label' in item && 'children' in item
}

function buildGroupTree(actions: ActionMeta[]): Array<ActionMeta | ActionGroup> {
  const ungrouped: ActionMeta[] = []
  const groupMap = new Map<string, ActionMeta[]>()

  for (const action of actions) {
    if (!action.group) {
      ungrouped.push(action)
    } else {
      const existing = groupMap.get(action.group) ?? []
      existing.push(action)
      groupMap.set(action.group, existing)
    }
  }

  const groups: ActionGroup[] = []
  for (const [label, children] of groupMap.entries()) {
    groups.push({ label, children })
  }

  return [...ungrouped, ...groups]
}

describe('Action group tree building', () => {
  it('separates ungrouped and grouped actions', () => {
    const actions = [
      makeAction({ uriKey: 'publish', name: 'Publish', group: null }),
      makeAction({ uriKey: 'export-csv', name: 'Export CSV', group: 'Export' }),
      makeAction({ uriKey: 'export-pdf', name: 'Export PDF', group: 'Export' }),
    ]

    const tree = buildGroupTree(actions)
    expect(tree).toHaveLength(2) // 1 ungrouped + 1 group
    expect((tree[0] as ActionMeta).name).toBe('Publish')
    expect(isActionGroup(tree[1])).toBe(true)
    expect((tree[1] as ActionGroup).label).toBe('Export')
    expect((tree[1] as ActionGroup).children).toHaveLength(2)
  })

  it('handles actions with no groups', () => {
    const actions = [
      makeAction({ uriKey: 'a1', name: 'Action 1' }),
      makeAction({ uriKey: 'a2', name: 'Action 2' }),
    ]

    const tree = buildGroupTree(actions)
    expect(tree).toHaveLength(2)
    expect(tree.every((item) => !isActionGroup(item))).toBe(true)
  })

  it('handles all grouped actions', () => {
    const actions = [
      makeAction({ uriKey: 'e1', name: 'Email', group: 'Notifications' }),
      makeAction({ uriKey: 's1', name: 'SMS', group: 'Notifications' }),
    ]

    const tree = buildGroupTree(actions)
    expect(tree).toHaveLength(1)
    expect(isActionGroup(tree[0])).toBe(true)
    expect((tree[0] as ActionGroup).children).toHaveLength(2)
  })
})

// ---------------------------------------------------------------------------
// Action response handling (mirrors ActionModal response logic)
// ---------------------------------------------------------------------------

describe('Action response handling', () => {
  it('parses message response from API envelope', () => {
    const apiResponse = {
      data: {
        type: 'message',
        data: { message: '3 posts published.' },
      },
    }
    const responseData = apiResponse.data
    expect(responseData.type).toBe('message')
    expect(responseData.data.message).toBe('3 posts published.')
  })

  it('parses danger response', () => {
    const apiResponse = {
      data: {
        type: 'danger',
        data: { message: 'Action failed.' },
      },
    }
    expect(apiResponse.data.type).toBe('danger')
  })

  it('parses redirect response', () => {
    const apiResponse = {
      data: {
        type: 'redirect',
        data: { url: 'https://example.com' },
      },
    }
    expect(apiResponse.data.type).toBe('redirect')
    expect(apiResponse.data.data.url).toBe('https://example.com')
  })

  it('parses visit response', () => {
    const apiResponse = {
      data: {
        type: 'visit',
        data: { path: '/resources/posts' },
      },
    }
    expect(apiResponse.data.type).toBe('visit')
  })
})

// ---------------------------------------------------------------------------
// Confirmation modal text customization
// ---------------------------------------------------------------------------

describe('Action confirmation customization', () => {
  it('uses custom confirm text', () => {
    const action = makeAction({
      confirmText: 'Are you absolutely sure?',
      confirmButtonText: 'Yes, do it',
      cancelButtonText: 'Go back',
    })
    expect(action.confirmText).toBe('Are you absolutely sure?')
    expect(action.confirmButtonText).toBe('Yes, do it')
    expect(action.cancelButtonText).toBe('Go back')
  })

  it('defaults to null for unset custom text', () => {
    const action = makeAction()
    expect(action.confirmText).toBeNull()
    expect(action.confirmButtonText).toBeNull()
    expect(action.cancelButtonText).toBeNull()
  })

  it('supports modal size', () => {
    expect(makeAction({ modalSize: 'lg' }).modalSize).toBe('lg')
    expect(makeAction({ modalSize: 'fullscreen' }).modalSize).toBe('fullscreen')
  })
})


// ---------------------------------------------------------------------------
// Custom component action metadata
// ---------------------------------------------------------------------------

describe("Custom component action", () => {
  it("carries customComponent key and props", () => {
    const action = makeAction({
      customComponent: "demo-custom-action",
      customComponentProps: { greeting: "Pick an option:", options: ["A", "B", "C"] },
    })
    expect(action.customComponent).toBe("demo-custom-action")
    expect(action.customComponentProps).toEqual({
      greeting: "Pick an option:",
      options: ["A", "B", "C"],
    })
  })

  it("defaults to null customComponent", () => {
    const action = makeAction()
    expect(action.customComponent).toBeNull()
    expect(action.customComponentProps).toEqual({})
  })

  it("custom component inline action has showInline true", () => {
    const action = makeAction({
      customComponent: "demo-custom-action",
      customComponentProps: { greeting: "Select:", options: ["Approve", "Review", "Reject"] },
      showInline: true,
    })
    expect(action.showInline).toBe(true)
    expect(action.customComponent).toBe("demo-custom-action")
  })

  it("needsConfirmation is true when customComponent is set even if withConfirmation is false", () => {
    // Mirror the ActionModal needsConfirmation logic:
    // const needsConfirmation = action.withConfirmation || hasFields || !!action.customComponent
    const action = makeAction({
      withConfirmation: false,
      customComponent: "demo-custom-action",
      customComponentProps: {},
    })
    const hasFields = false
    const needsConfirmation = action.withConfirmation || hasFields || !!action.customComponent
    expect(needsConfirmation).toBe(true)
  })

  it("needsConfirmation is false when all three conditions are false", () => {
    const action = makeAction({
      withConfirmation: false,
      customComponent: null,
      customComponentProps: {},
    })
    const hasFields = false
    const needsConfirmation = action.withConfirmation || hasFields || !!action.customComponent
    expect(needsConfirmation).toBe(false)
  })
})


// ---------------------------------------------------------------------------
// openCreate and openDetail response types
// ---------------------------------------------------------------------------

describe('Action openCreate and openDetail responses', () => {
  it('parses openCreate response', () => {
    const apiResponse = {
      data: {
        type: 'openCreate',
        data: { resource: 'posts' },
      },
    }
    const responseData = apiResponse.data
    expect(responseData.type).toBe('openCreate')
    expect(responseData.data.resource).toBe('posts')
  })

  it('parses openDetail response with numeric id', () => {
    const apiResponse = {
      data: {
        type: 'openDetail',
        data: { resource: 'posts', recordId: 42 },
      },
    }
    const responseData = apiResponse.data
    expect(responseData.type).toBe('openDetail')
    expect(responseData.data.resource).toBe('posts')
    expect(responseData.data.recordId).toBe(42)
  })

  it('parses openDetail response with string id', () => {
    const apiResponse = {
      data: {
        type: 'openDetail',
        data: { resource: 'posts', recordId: 'abc-123' },
      },
    }
    expect(apiResponse.data.data.recordId).toBe('abc-123')
  })

  it('ActionMeta uriKey is set correctly for openCreate action', () => {
    const action = makeAction({ uriKey: 'open-create-demo' })
    expect(action.uriKey).toBe('open-create-demo')
  })
})


// ---------------------------------------------------------------------------
// Sole action disabled logic (mirrors ResourceIndex bulk action bar logic)
// ---------------------------------------------------------------------------

function computeBulkDisabledActions(
  actions: ActionMeta[],
  selectedCount: number,
): Set<string> {
  const bulkDisabledActions = new Set<string>()
  for (const action of actions) {
    if (action.sole && selectedCount > 1) {
      bulkDisabledActions.add(action.uriKey)
      continue
    }
    // (authorization check omitted in this unit — covered by integration)
  }
  return bulkDisabledActions
}

describe('Sole action disabled logic', () => {
  const soleAction = makeAction({ uriKey: 'send-notifications', name: 'Send Notifications', sole: true, executionMode: 'sole' })
  const bulkAction = makeAction({ uriKey: 'export-csv', name: 'Export CSV', sole: false, executionMode: 'bulk' })

  it('does not disable sole action when exactly 1 record is selected', () => {
    const disabled = computeBulkDisabledActions([soleAction, bulkAction], 1)
    expect(disabled.has('send-notifications')).toBe(false)
    expect(disabled.has('export-csv')).toBe(false)
  })

  it('disables sole action when 2 records are selected', () => {
    const disabled = computeBulkDisabledActions([soleAction, bulkAction], 2)
    expect(disabled.has('send-notifications')).toBe(true)
    expect(disabled.has('export-csv')).toBe(false)
  })

  it('disables sole action when many records are selected', () => {
    const disabled = computeBulkDisabledActions([soleAction, bulkAction], 10)
    expect(disabled.has('send-notifications')).toBe(true)
    expect(disabled.has('export-csv')).toBe(false)
  })

  it('does not affect normal bulk actions regardless of selection count', () => {
    const disabled2 = computeBulkDisabledActions([bulkAction], 2)
    const disabled10 = computeBulkDisabledActions([bulkAction], 10)
    expect(disabled2.has('export-csv')).toBe(false)
    expect(disabled10.has('export-csv')).toBe(false)
  })
})
