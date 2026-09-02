// API origin is env-driven so the SPA can run same-origin (dev proxy, empty
// string → relative paths — the current production setup) or cross-origin
// (a future subdomain split: VITE_API_ORIGIN=https://api.example.com). Never
// hardcode the origin here.
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

type RequestOptions = {
  // Skip the global hard-redirect to /login on a 401. Used by the auth probe
  // (GET /auth/me), where a 401 is the *expected* "logged out" signal and the
  // router guard — not the api client — owns the redirect so it can preserve
  // the originally-requested destination via ?redirect=.
  skipAuthRedirect?: boolean
}

async function request<T>(
  method: Method,
  path: string,
  body?: unknown,
  isForm = false,
  isRetry = false,
  options: RequestOptions = {},
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
    return request<T>(method, path, body, isForm, true, options)
  }

  const data = await response.json().catch(() => ({}))

  if (response.status === 401) {
    resetCsrf()
    // A mid-session 401 (session expired while on a protected page) bounces to
    // login, preserving the current location so the user returns after re-login.
    // The auth probe opts out via skipAuthRedirect — the router guard handles
    // its redirect and sets ?redirect= to the deep link the user actually wanted.
    if (!options.skipAuthRedirect && window.location.pathname !== '/login') {
      const target = window.location.pathname + window.location.search
      window.location.href = `/login?redirect=${encodeURIComponent(target)}`
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
    .flatMap(([k, v]) =>
      // Arrays repeat as `key[]=a&key[]=b` so Laravel parses them as an array.
      // Joining them into one value would arrive as the string "a,b".
      Array.isArray(v)
        ? v.map((item) => `${encodeURIComponent(k)}[]=${encodeURIComponent(String(item))}`)
        : [`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`],
    )
    .join('&')
  return qs ? `${path}?${qs}` : path
}

/**
 * Fetch a file and hand it to the browser as a download.
 *
 * Deliberately not a plain `<a href>` navigation: that would bypass this
 * client's origin handling (VITE_API_ORIGIN) and turn any auth or server error
 * into a page of raw text replacing the app. Fetching first means a failure
 * throws an ApiError the caller can surface in place.
 *
 * Uses the filename the server sent in Content-Disposition — the streamer
 * exposes that header to the browser precisely so this can read it.
 */
async function download(path: string, params?: Record<string, unknown>): Promise<void> {
  const response = await fetch(`${BASE_URL}${buildUrl(path, params)}`, {
    method: 'GET',
    credentials: 'include',
  })

  if (!response.ok) {
    throw new ApiError(response.status, {})
  }

  const match = /filename="([^"]+)"/.exec(response.headers.get('content-disposition') ?? '')
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = match?.[1] ?? 'download.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  // Revoking immediately can cancel the download in some browsers.
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

const api = {
  get: <T>(path: string, params?: Record<string, unknown>, options?: RequestOptions) =>
    request<T>('GET', buildUrl(path, params), undefined, false, false, options),
  download,
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form, true),
}

export default api
