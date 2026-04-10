import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'

// --- Mocks ---
vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    upload: vi.fn(),
  },
  ApiError: class ApiError extends Error {
    constructor(public status: number, message: string, public errors?: unknown[]) {
      super(message)
      this.name = 'ApiError'
    }
    errorsByField() { return {} }
    errorSummary() { return this.message }
  },
}))

vi.mock('@/contexts/ToastContext', () => ({
  useToast: () => ({ addToast: vi.fn() }),
}))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Test User', email: 'test@example.com' } }),
  TwoFactorRequiredError: class TwoFactorRequiredError extends Error {
    constructor() { super('two_factor_required'); this.name = 'TwoFactorRequiredError' }
  },
}))

vi.mock('@/lib/config', () => ({
  config: {
    profile: {
      avatar: { enabled: true },
      two_factor: { enabled: true },
      menu: { enabled: true },
    },
  },
  BASE_PATH: '/martis',
  API_BASE_URL: 'http://localhost/martis',
}))

vi.mock('@/components/Loader', () => ({
  MartisLoader: () => <div data-testid="loader">Loading</div>,
}))

// Mock logo import
vi.mock('@images/logo.png', () => ({ default: '/logo.png' }))

import { api } from '@/lib/api'
import { AccountSection } from '@/components/Profile/AccountSection'
import { PasswordSection } from '@/components/Profile/PasswordSection'
import { AvatarSection } from '@/components/Profile/AvatarSection'
import { SecuritySection } from '@/components/Profile/SecuritySection'
import { ProfilePage } from '@/pages/Profile'
import { TwoFactorChallengePage } from '@/pages/TwoFactorChallenge'

function wrap(ui: React.ReactElement) {
  return render(<MemoryRouter>{ui}</MemoryRouter>)
}

// --- AccountSection tests ---
describe('AccountSection', () => {
  it('renders name and email fields', () => {
    wrap(<AccountSection name="Alice" email="alice@example.com" onUpdate={vi.fn()} />)
    expect(screen.getByDisplayValue('Alice')).toBeDefined()
    expect(screen.getByDisplayValue('alice@example.com')).toBeDefined()
  })

  it('calls api.patch on form submit', async () => {
    vi.mocked(api.patch).mockResolvedValue({ name: 'Alice', email: 'alice@example.com' })
    wrap(<AccountSection name="Alice" email="alice@example.com" onUpdate={vi.fn()} />)
    const btn = screen.getByRole('button', { name: /save/i })
    fireEvent.click(btn)
    await waitFor(() => {
      expect(api.patch).toHaveBeenCalledWith('/api/profile', expect.any(Object))
    })
  })
})

// --- PasswordSection tests ---
describe('PasswordSection', () => {
  it('renders password fields', () => {
    wrap(<PasswordSection />)
    expect(screen.getByLabelText('Current Password')).toBeDefined()
    expect(screen.getByLabelText('New Password')).toBeDefined()
    expect(screen.getByLabelText('Confirm New Password')).toBeDefined()
  })

  it('shows mismatch error when passwords differ', async () => {
    wrap(<PasswordSection />)
    fireEvent.change(screen.getByLabelText('New Password'), { target: { value: 'password123' } })
    fireEvent.change(screen.getByLabelText('Confirm New Password'), { target: { value: 'different123' } })
    fireEvent.click(screen.getByRole('button', { name: /update password/i }))
    await waitFor(() => {
      expect(screen.getByText(/do not match/i)).toBeDefined()
    })
  })

  it('shows min length error for short password', async () => {
    wrap(<PasswordSection />)
    fireEvent.change(screen.getByLabelText('New Password'), { target: { value: 'short' } })
    fireEvent.change(screen.getByLabelText('Confirm New Password'), { target: { value: 'short' } })
    fireEvent.click(screen.getByRole('button', { name: /update password/i }))
    await waitFor(() => {
      expect(screen.getByText(/at least 8/i)).toBeDefined()
    })
  })
})

// --- AvatarSection tests ---
describe('AvatarSection', () => {
  it('renders initials when no avatar url', () => {
    wrap(<AvatarSection avatarUrl={null} name="Alice Bob" onUpdate={vi.fn()} />)
    expect(screen.getByText('AB')).toBeDefined()
  })

  it('renders avatar image when url provided', () => {
    wrap(<AvatarSection avatarUrl="https://example.com/avatar.jpg" name="Alice" onUpdate={vi.fn()} />)
    const img = screen.getByRole('img', { name: 'Alice' })
    expect(img).toBeDefined()
    expect(img.getAttribute('src')).toBe('https://example.com/avatar.jpg')
  })

  it('shows remove button when avatar url provided', () => {
    wrap(<AvatarSection avatarUrl="https://example.com/avatar.jpg" name="Alice" onUpdate={vi.fn()} />)
    expect(screen.getByRole('button', { name: /remove photo/i })).toBeDefined()
  })

  it('does not show remove button when no avatar', () => {
    wrap(<AvatarSection avatarUrl={null} name="Alice" onUpdate={vi.fn()} />)
    expect(screen.queryByRole('button', { name: /remove/i })).toBeNull()
  })
})

// --- SecuritySection tests ---
describe('SecuritySection', () => {
  it('shows enable button when 2FA disabled', () => {
    wrap(<SecuritySection twoFactorEnabled={false} onUpdate={vi.fn()} />)
    expect(screen.getByRole('button', { name: /enable/i })).toBeDefined()
  })

  it('shows disable button when 2FA enabled', () => {
    wrap(<SecuritySection twoFactorEnabled={true} onUpdate={vi.fn()} />)
    expect(screen.getByRole('button', { name: /disable/i })).toBeDefined()
  })

  it('shows enabled badge when 2FA is on', () => {
    wrap(<SecuritySection twoFactorEnabled={true} onUpdate={vi.fn()} />)
    expect(screen.getByText('Enabled')).toBeDefined()
  })

  it('shows disabled badge when 2FA is off', () => {
    wrap(<SecuritySection twoFactorEnabled={false} onUpdate={vi.fn()} />)
    expect(screen.getByText('Disabled')).toBeDefined()
  })
})

// --- ProfilePage tests ---
describe('ProfilePage', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({
      name: 'Test User',
      email: 'test@example.com',
      avatar_url: null,
      two_factor_enabled: false,
    })
  })

  it('renders profile page with all sections after loading', async () => {
    wrap(<ProfilePage />)
    await waitFor(() => {
      expect(screen.getByText('Account Information')).toBeDefined()
      expect(screen.getByText('Change Password')).toBeDefined()
      expect(screen.getByText('Profile Picture')).toBeDefined()
      // Use heading role to avoid ambiguity
      expect(screen.getByRole('heading', { name: /two-factor authentication/i })).toBeDefined()
    })
  })

  it('renders loader initially', () => {
    vi.mocked(api.get).mockReturnValue(new Promise(() => {}))
    wrap(<ProfilePage />)
    expect(screen.getByTestId('loader')).toBeDefined()
  })

  it('falls back to auth user when api fails', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('not found'))
    wrap(<ProfilePage />)
    await waitFor(() => {
      expect(screen.getByDisplayValue('Test User')).toBeDefined()
    })
  })
})

// --- TwoFactorChallengePage tests ---
describe('TwoFactorChallengePage', () => {
  it('renders challenge form', () => {
    wrap(<TwoFactorChallengePage />)
    expect(screen.getByRole('button', { name: /verify/i })).toBeDefined()
  })

  it('toggles to recovery code mode', () => {
    wrap(<TwoFactorChallengePage />)
    const toggleBtn = screen.getByText(/use a recovery code/i)
    fireEvent.click(toggleBtn)
    expect(screen.getByText(/use authenticator code/i)).toBeDefined()
  })

  it('calls 2fa challenge api on submit', async () => {
    vi.mocked(api.post).mockResolvedValue({})
    wrap(<TwoFactorChallengePage />)
    const input = screen.getByRole('textbox')
    fireEvent.change(input, { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: /verify/i }))
    await waitFor(() => {
      expect(api.post).toHaveBeenCalledWith('/api/2fa/challenge', expect.any(Object))
    })
  })
})
