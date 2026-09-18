export type ValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly errors: ValidationErrors

  constructor(
    message: string,
    status: number,
    errors: ValidationErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

type UnauthorizedHandler = () => void
let unauthorizedHandler: UnauthorizedHandler | undefined
let csrfReady = false

export function setUnauthorizedHandler(handler?: UnauthorizedHandler) {
  unauthorizedHandler = handler
}

function cookie(name: string) {
  const value = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`))?.split('=').slice(1).join('=')
  return value ? decodeURIComponent(value) : undefined
}

async function ensureCsrfCookie() {
  if (csrfReady || typeof document === 'undefined') return
  const response = await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
  if (!response.ok && response.status !== 204) throw new ApiError('Unable to establish a secure session.', response.status)
  csrfReady = true
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
  csrf?: boolean
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase()
  if (options.csrf !== false && !['GET', 'HEAD', 'OPTIONS'].includes(method)) await ensureCsrfCookie()

  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')
  const xsrf = typeof document === 'undefined' ? undefined : cookie('XSRF-TOKEN')
  if (xsrf) headers.set('X-XSRF-TOKEN', xsrf)
  let body: BodyInit | undefined
  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
    body = JSON.stringify(options.body)
  }

  const response = await fetch(path, { ...options, method, headers, body, credentials: 'include' })
  const payload = await response.json().catch(() => ({})) as {
    data?: T
    message?: string
    errors?: ValidationErrors
    error?: { code?: string; message?: string; detail_code?: string }
  }

  if (!response.ok) {
    if (response.status === 419 && options.csrf !== false) csrfReady = false
    if (response.status === 401) unauthorizedHandler?.()
    const message = payload.error?.message ?? payload.message ?? `Request failed (${response.status}).`
    throw new ApiError(message, response.status, payload.errors)
  }
  return (payload.data === undefined ? payload : payload.data) as T
}

export function firstValidationError(error: unknown) {
  if (!(error instanceof ApiError)) return error instanceof Error ? error.message : 'An unexpected error occurred.'
  return Object.values(error.errors).flat()[0] ?? error.message
}

export function resetApiClientForTests() {
  csrfReady = false
  unauthorizedHandler = undefined
}
