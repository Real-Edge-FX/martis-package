import { beforeEach, describe, expect, it } from 'vitest'
import {
  DASHBOARD_LAYOUTS_KEY,
  forgetDashboardLayouts,
  recallDashboardLayout,
  rememberDashboardLayout,
} from '@/lib/dashboards'

describe('the remembered card layout of a dashboard', () => {
  beforeEach(() => {
    window.localStorage.removeItem(DASHBOARD_LAYOUTS_KEY)
  })

  it('keeps only the spans of each card', () => {
    rememberDashboardLayout('team', [
      { width: 12, widthMd: null, widthLg: 8, name: 'Hero', value: 42 } as never,
      { width: 3 },
    ])

    expect(recallDashboardLayout('team')).toEqual([
      { width: 12, widthMd: null, widthLg: 8 },
      { width: 3, widthMd: null, widthLg: null },
    ])
    expect(window.localStorage.getItem(DASHBOARD_LAYOUTS_KEY)).not.toContain('Hero')
  })

  it('has nothing for an unknown dashboard, an empty one or no key', () => {
    rememberDashboardLayout('empty', [])

    expect(recallDashboardLayout('other')).toBeNull()
    expect(recallDashboardLayout('empty')).toBeNull()
    expect(recallDashboardLayout(null)).toBeNull()
  })

  it('survives a corrupt entry and is forgotten on sign-out', () => {
    window.localStorage.setItem(DASHBOARD_LAYOUTS_KEY, '{not json')
    expect(recallDashboardLayout('team')).toBeNull()

    rememberDashboardLayout('team', [{ width: 6 }])
    forgetDashboardLayouts()
    expect(recallDashboardLayout('team')).toBeNull()
  })

  it('keeps at most fifty dashboards, dropping the oldest', () => {
    for (let i = 0; i < 55; i++) rememberDashboardLayout(`d${i}`, [{ width: 4 }])

    expect(recallDashboardLayout('d0')).toBeNull()
    expect(recallDashboardLayout('d4')).toBeNull()
    expect(recallDashboardLayout('d5')).not.toBeNull()
    expect(recallDashboardLayout('d54')).not.toBeNull()
  })
})
