import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ApiError, apiRequest, resetApiClientForTests, setUnauthorizedHandler } from '../api/client'
import { AuthProvider } from '../auth/AuthContext'
import { LoginPage } from '../auth/LoginPage'
import { ProtectedRoute } from '../auth/ProtectedRoute'

const response = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' },
}))

afterEach(() => {
  vi.restoreAllMocks()
  resetApiClientForTests()
  localStorage.clear()
})

describe('authentication routing', () => {
  it('redirects an unauthenticated visitor away from trading routes', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => response({ message: 'Unauthenticated.' }, 401))
    render(<MemoryRouter initialEntries={['/manual-trading']}><AuthProvider><Routes>
      <Route path="/login" element={<div>Secure login</div>} />
      <Route element={<ProtectedRoute />}><Route path="/manual-trading" element={<div>Trading terminal</div>} /></Route>
    </Routes></AuthProvider></MemoryRouter>)
    expect(await screen.findByText('Secure login')).toBeInTheDocument()
    expect(screen.queryByText('Trading terminal')).not.toBeInTheDocument()
  })

  it('submits login credentials and renders backend validation errors', async () => {
    vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => response({ message: 'Unauthenticated.' }, 401))
      .mockImplementationOnce(() => Promise.resolve(new Response(null, { status: 204 })))
      .mockImplementationOnce(() => response({ message: 'Invalid credentials.' }, 422))
    render(<MemoryRouter initialEntries={['/login']}><AuthProvider><Routes><Route path="/login" element={<LoginPage />} /></Routes></AuthProvider></MemoryRouter>)
    await screen.findByText('Welcome back')
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'wrong@nexa.local' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'incorrect' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid credentials.')
  })
})

describe('API client errors and session cookies', () => {
  it('returns field-level validation details and includes credentials', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(() => response({
      message: 'The given data was invalid.',
      errors: { email: ['The email field is required.'] },
    }, 422))
    await expect(apiRequest('/api/v1/example', { method: 'POST', csrf: false, body: {} })).rejects.toMatchObject({
      status: 422,
      errors: { email: ['The email field is required.'] },
    })
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/example', expect.objectContaining({ credentials: 'include' }))
  })

  it('notifies auth state on unauthorized responses', async () => {
    const unauthorized = vi.fn()
    setUnauthorizedHandler(unauthorized)
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => response({ message: 'Unauthenticated.' }, 401))
    await expect(apiRequest('/api/v1/private', { csrf: false })).rejects.toBeInstanceOf(ApiError)
    await waitFor(() => expect(unauthorized).toHaveBeenCalledOnce())
  })
})
