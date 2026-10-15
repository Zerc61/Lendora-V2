/*
 * Klien HTTP tipis untuk API Lendora. Session Laravel, bukan token:
 * - kredensial dikirim sebagai cookie (same-origin lewat proxy Vite),
 * - CSRF: baca cookie XSRF-TOKEN, kirim sebagai header X-XSRF-TOKEN,
 * - 419 (CSRF kedaluwarsa) diprim ulang lalu dicoba sekali lagi.
 */

const API_BASE = '/api'

export class ApiError extends Error {
  constructor(status, data = null) {
    const message = data?.message ?? 'Terjadi kesalahan pada server.'
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.data = data
    this.errors = data?.errors ?? null
  }
}

function readXSRFToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function primeCsrf() {
  await fetch(`${API_BASE}/csrf-cookie`, {
    method: 'GET',
    credentials: 'same-origin',
  })
}

async function request(path, { method = 'GET', body, headers = {}, retried = false } = {}) {
  let token = readXSRFToken()
  const isFormData = body instanceof FormData

  if (method !== 'GET' && token === null) {
    await primeCsrf()
    token = readXSRFToken()
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      // FormData memasang Content-Type sendiri (dengan boundary).
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
  })

  if (response.status === 419 && method !== 'GET' && !retried) {
    await primeCsrf()
    return request(path, { method, body, headers, retried: true })
  }

  const text = await response.text()
  const data = text ? JSON.parse(text) : null

  if (!response.ok) {
    throw new ApiError(response.status, data)
  }

  return data
}

export function apiGet(path, headers) {
  return request(path, { headers })
}

export function apiPost(path, body, headers) {
  return request(path, { method: 'POST', body, headers })
}

export function apiPatch(path, body, headers) {
  return request(path, { method: 'PATCH', body, headers })
}

export function apiDelete(path, body, headers) {
  return request(path, { method: 'DELETE', body, headers })
}

/*
 * Unduh berkas (mis. ekspor CSV) dengan session dan CSRF yang sama.
 * Mengembalikan Blob bila respons OK, ApiError bila gagal.
 */
export async function apiBlob(path) {
  let token = readXSRFToken()

  if (token === null) {
    await primeCsrf()
    token = readXSRFToken()
  }

  const response = await fetch(`${API_BASE}${path}`, {
    credentials: 'same-origin',
    headers: {
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    },
  })

  if (!response.ok) {
    let data = null
    try {
      data = await response.json()
    } catch {
      data = null
    }
    throw new ApiError(response.status, data)
  }

  return response.blob()
}
