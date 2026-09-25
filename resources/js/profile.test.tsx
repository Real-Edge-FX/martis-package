import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

// --- Mocks ---
// One spy for every render: the profile page loads once per `updateUser`.
const { updateUser } = vi.hoisted(() => ({ updateUser: vi.fn() }))

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
  useAuth: () => ({ user: { id: 1, name: 'Test User', email: 'test@example.com' }, updateUser }),
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

  it('passes the profile the server saved to onUpdate, not the form values', async () => {
    // A resource may drop or normalise a key: this one keeps the e-mail.
    const saved = { name: 'Alice Smith', email: 'alice@example.com', avatar_url: null, two_factor_enabled: false }
    vi.mocked(api.patch).mockResolvedValue(saved)
    const onUpdate = vi.fn()
    wrap(<AccountSection name="Alice" email="alice@example.com" onUpdate={onUpdate} />)

    fireEvent.change(screen.getByDisplayValue('Alice'), { target: { value: 'Alice Smith' } })
    fireEvent.change(screen.getByDisplayValue('alice@example.com'), { target: { value: 'other@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(onUpdate).toHaveBeenCalledWith(saved)
    })
  })
})

// --- PasswordSection tests ---
describe('PasswordSection', () => {
  it('renders password fields and reveals the checklist once the user types', () => {
    wrap(<PasswordSection />)
    expect(screen.getByLabelText('Current Password')).toBeDefined()
    // New / Confirm inputs render as PasswordFieldInput — id matches `field.attribute`.
    expect(document.getElementById('password')).not.toBeNull()
    expect(document.getElementById('password_confirmation')).not.toBeNull()

    // Checklist is hidden while the input is empty — avoids misleading the
    // user in edit flows where "empty means keep current".
    expect(document.querySelector('[data-testid="password-requirements-password"]')).toBeNull()

    // As soon as the user types anything, the checklist appears.
    fireEvent.change(document.getElementById('password')!, { target: { value: 'a' } })
    expect(document.querySelector('[data-testid="password-requirements-password"]')).not.toBeNull()
  })

  it('live match indicator flips to mismatch when the confirmation differs', () => {
    wrap(<PasswordSection />)
    fireEvent.change(document.getElementById('password')!, { target: { value: 'Password123!' } })
    fireEvent.change(document.getElementById('password_confirmation')!, { target: { value: 'Different!' } })
    const status = document.querySelector('[data-testid="password-confirm-status-password_confirmation"]')
    expect(status).not.toBeNull()
    expect(status!.getAttribute('data-status')).toBe('mismatch')
  })

  it('checklist flips each requirement to passes=true when the criterion is satisfied', () => {
    wrap(<PasswordSection />)
    const input = document.getElementById('password')!
    fireEvent.change(input, { target: { value: 'Str0ng-Pwd!' } })

    const checklist = document.querySelector('[data-testid="password-requirements-password"]')!
    expect(checklist.querySelector('[data-requirement="minLength"]')!.getAttribute('data-passes')).toBe('true')
    expect(checklist.querySelector('[data-requirement="uppercase"]')!.getAttribute('data-passes')).toBe('true')
    expect(checklist.querySelector('[data-requirement="number"]')!.getAttribute('data-passes')).toBe('true')
    expect(checklist.querySelector('[data-requirement="symbol"]')!.getAttribute('data-passes')).toBe('true')
  })
})

// --- AvatarSection tests ---
describe('AvatarSection', () => {
  it('renders the server initials on their palette token when no avatar url', () => {
    wrap(<AvatarSection avatarUrl={null} name="Alice Bob" initials="AB" palette={3} onUpdate={vi.fn()} />)
    const circle = screen.getByText('AB')
    expect(circle.style.backgroundColor).toBe('var(--martis-avatar-3)')
  })

  it('renders the user glyph when the server sends no initials', () => {
    const { container } = wrap(<AvatarSection avatarUrl={null} name="" initials="" palette={16} onUpdate={vi.fn()} />)
    const circle = container.querySelector('.rounded-full.h-20') as HTMLElement
    expect(circle.textContent).toBe('')
    expect(circle.querySelector('svg')).not.toBeNull()
    expect(circle.style.backgroundColor).toBe('var(--martis-avatar-16)')
  })

  it('renders avatar image when url provided', () => {
    wrap(<AvatarSection avatarUrl="https://example.com/avatar.jpg" name="Alice" initials="A" palette={7} onUpdate={vi.fn()} />)
    const img = screen.getByRole('img', { name: 'Alice' })
    expect(img).toBeDefined()
    expect(img.getAttribute('src')).toBe('https://example.com/avatar.jpg')
  })

  it('shows remove button when avatar url provided', () => {
    wrap(<AvatarSection avatarUrl="https://example.com/avatar.jpg" name="Alice" initials="A" palette={7} onUpdate={vi.fn()} />)
    expect(screen.getByRole('button', { name: /remove photo/i })).toBeDefined()
  })

  it('does not show remove button when no avatar', () => {
    wrap(<AvatarSection avatarUrl={null} name="Alice" initials="A" palette={7} onUpdate={vi.fn()} />)
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
    updateUser.mockClear()
    vi.mocked(api.get).mockResolvedValue({
      name: 'Test User',
      email: 'test@example.com',
      avatar_url: null,
      two_factor_enabled: false,
      avatar_initials: 'TU',
      avatar_palette: 4,
    })
  })

  it('gives the Topbar the loaded profile', async () => {
    wrap(<ProfilePage />)
    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledWith({
        name: 'Test User',
        email: 'test@example.com',
        avatar_url: null,
        avatar_initials: 'TU',
        avatar_palette: 4,
      })
    })
  })

  it('paints the avatar with the loaded initials and palette slot', async () => {
    wrap(<ProfilePage />)
    const circle = await screen.findByText('TU')
    expect(circle.style.backgroundColor).toBe('var(--martis-avatar-4)')
  })

  it('gives the Topbar the saved name after an account update', async () => {
    vi.mocked(api.patch).mockResolvedValue({
      name: 'Renamed User',
      email: 'test@example.com',
      avatar_url: null,
      two_factor_enabled: false,
      avatar_initials: 'RU',
      avatar_palette: 9,
    })
    wrap(<ProfilePage />)
    const nameInput = await screen.findByDisplayValue('Test User')
    updateUser.mockClear()

    fireEvent.change(nameInput, { target: { value: 'Renamed User' } })
    fireEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(updateUser).toHaveBeenCalledWith({
        name: 'Renamed User',
        email: 'test@example.com',
        avatar_url: null,
        avatar_initials: 'RU',
        avatar_palette: 9,
      })
    })
    // The page's own avatar follows the new name too.
    expect(await screen.findByText('RU')).toBeDefined()
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

    // The OTP input is six discrete `<input>` cells (Fase 6 redesign).
    // The component supports paste-to-fill — fire a paste event on the
    // first cell and let the component distribute the digits.
    const cells = screen.getAllByRole('textbox')
    expect(cells.length).toBe(6)

    fireEvent.paste(cells[0], {
      clipboardData: {
        getData: () => '123456',
      },
    })

    fireEvent.click(screen.getByRole('button', { name: /verify/i }))
    await waitFor(() => {
      expect(api.post).toHaveBeenCalledWith('/api/2fa/challenge', expect.any(Object))
    })
  })
})
