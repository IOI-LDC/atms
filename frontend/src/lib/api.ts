// API origin is env-driven so the SPA can run same-origin (dev proxy, empty
// string → relative paths) or cross-origin (production subdomain split:
// VITE_API_ORIGIN=https://atmsapi.inova.krd). Never hardcode the origin here.
const API_ORIGIN = import.meta.env.VITE_API_ORIGIN ?? ''
const BASE_URL = `${API_ORIGIN}/api`
const CSRF_URL = `${API_ORIGIN}/sanctum/csrf-cookie`

let csrfInitialized = false
let csrfPromise: Promise<void> | null = null

// Single-flight: when several mutations fire in parallel before the cookie is
// set, they must share ONE /sanctum/csrf-cookie request. Firing one per call
// races each response's Set-Cookie and can land requests on a fresh/empty
// session (intermittent 401s).
async function initCsrf(): Promise<void> {
  if (csrfInitialized) return
  if (!csrfPromise) {
    csrfPromise = fetch(CSRF_URL, { credentials: 'include' })
      .then(() => {
        csrfInitialized = true
      })
      .finally(() => {
        csrfPromise = null
      })
  }
  return csrfPromise
}

export function resetCsrf(): void {
  csrfInitialized = false
  csrfPromise = null
}

function getXsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1] ?? '') : ''
}

export class ApiError extends Error {
  readonly status: number
  readonly data: Record<string, unknown>

  constructor(status: number, data: Record<string, unknown>) {
    super((data?.message as string) ?? `HTTP ${status}`)
    this.status = status
    this.data = data
  }

  get validationErrors(): Record<string, string[]> | null {
    if (this.status === 422 && this.data.errors) {
      return this.data.errors as Record<string, string[]>
    }
    return null
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

async function request<T>(
  method: Method,
  path: string,
  body?: unknown,
  isForm = false,
  isRetry = false,
): Promise<T> {
  const mutating = method !== 'GET'

  if (mutating) await initCsrf()

  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (mutating) {
    headers['X-XSRF-TOKEN'] = getXsrfToken()
  }

  let bodyInit: BodyInit | undefined
  if (body !== undefined) {
    if (isForm && body instanceof FormData) {
      bodyInit = body
    } else {
      headers['Content-Type'] = 'application/json'
      bodyInit = JSON.stringify(body)
    }
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers,
    body: bodyInit,
    credentials: 'include',
  })

  if (response.status === 204) return undefined as T

  // Stale CSRF token (long idle / session regeneration) surfaces as 419
  // "Page Expired". Refresh the cookie once and replay the request before
  // surfacing the failure — a silent retry is far better UX than a hard error.
  if (response.status === 419 && !isRetry) {
    resetCsrf()
    await initCsrf()
    return request<T>(method, path, body, isForm, true)
  }

  const data = await response.json().catch(() => ({}))

  if (response.status === 401) {
    resetCsrf()
    if (window.location.pathname !== '/login') {
      window.location.href = '/login'
    }
    throw new ApiError(401, data)
  }

  if (!response.ok) {
    throw new ApiError(response.status, data)
  }

  return data as T
}

function buildUrl(path: string, params?: Record<string, unknown>): string {
  if (!params) return path
  const qs = Object.entries(params)
    .filter(([, v]) => v != null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&')
  return qs ? `${path}?${qs}` : path
}

const api = {
  get: <T>(path: string, params?: Record<string, unknown>) =>
    request<T>('GET', buildUrl(path, params)),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form, true),
}

export default api
