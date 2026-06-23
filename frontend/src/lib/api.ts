const BASE_URL = '/api'

let csrfInitialized = false

async function initCsrf(): Promise<void> {
  if (csrfInitialized) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  csrfInitialized = true
}

export function resetCsrf(): void {
  csrfInitialized = false
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
  get:    <T>(path: string, params?: Record<string, unknown>) => request<T>('GET',    buildUrl(path, params)),
  post:   <T>(path: string, body?: unknown)                   => request<T>('POST',   path, body),
  patch:  <T>(path: string, body?: unknown)                   => request<T>('PATCH',  path, body),
  put:    <T>(path: string, body?: unknown)                   => request<T>('PUT',    path, body),
  delete: <T>(path: string)                                   => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData)                   => request<T>('POST',   path, form, true),
}

export default api
