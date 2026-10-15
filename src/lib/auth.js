/*
 * Status autentikasi dan konteks organisasi aktif. Satu sumber kebenaran
 * untuk shell dan guard router. Disimpan sebagai reactive modul: tanpa Pinia.
 */

import { reactive } from 'vue'
import { apiPost, apiGet } from './http'
import { ApiError } from './http'

export const auth = reactive({
  ready: false,
  user: null,
  role: null,
  organizations: [],
  activeOrganizationId: null,
})

// Rate limiting tracking
const rateLimit = reactive({
  attempts: {}, // email -> { count, lastAttempt, blockUntil }
  MAX_ATTEMPTS: 5,
  BLOCK_TIME_MS: 15 * 60 * 1000, // 15 minutes
})

function reset() {
  auth.user = null
  auth.role = null
  auth.organizations = []
  auth.activeOrganizationId = null
}

function applyPayload(payload) {
  auth.user = payload.data ?? null
  auth.organizations = payload.organizations?.data ?? payload.organizations ?? []
  auth.activeOrganizationId = payload.active_organization_id ?? null
  auth.role = payload.role ?? null
}

/** Muat /me sekali. Aman dipanggil berulang: hanya berjalan saat belum siap. */
export async function bootstrap() {
  if (auth.ready) {
    return
  }
  try {
    applyPayload(await apiGet('/me'))
  } catch {
    // 401 artinya belum login. Skenario lain (server mati) juga perlu
    // membiarkan halaman login tampil dengan pesan, bukan menggantung.
    reset()
  } finally {
    auth.ready = true
  }
}

/**
 * Cek apakah login diizinkan berdasarkan rate limiting
 * @param {string} email - Email pengguna
 * @returns {Object} { allowed: boolean, retryAfter: number|null }
 */
function checkRateLimit(email) {
  const now = Date.now()
  const attempt = rateLimit.attempts[email] || { count: 0, lastAttempt: 0, blockUntil: 0 }

  // Jika masih dalam periode blokir
  if (now < attempt.blockUntil) {
    return {
      allowed: false,
      retryAfter: Math.ceil((attempt.blockUntil - now) / 1000),
    }
  }

  // Reset jika sudah lewati periode blokir
  if (now > attempt.blockUntil) {
    attempt.count = 0
    attempt.blockUntil = 0
  }

  return {
    allowed: attempt.count < rateLimit.MAX_ATTEMPTS,
    retryAfter: null,
  }
}

/** Catat upaya login gagal */
function recordFailedAttempt(email) {
  const now = Date.now()
  if (!rateLimit.attempts[email]) {
    rateLimit.attempts[email] = { count: 0, lastAttempt: 0, blockUntil: 0 }
  }

  const attempt = rateLimit.attempts[email]
  attempt.count += 1
  attempt.lastAttempt = now

  // Jika mencapai maksimal upaya, set blokir
  if (attempt.count >= rateLimit.MAX_ATTEMPTS) {
    attempt.blockUntil = now + rateLimit.BLOCK_TIME_MS
  }
}

/** Reset upaya login setelah login berhasil */
function resetRateLimit(email) {
  if (rateLimit.attempts[email]) {
    rateLimit.attempts[email] = { count: 0, lastAttempt: 0, blockUntil: 0 }
  }
}
export async function login(credentials) {
  const email = credentials.email

  // If email is not provided, let the backend handle validation
  if (!email) {
    return await apiPost('/login', credentials)
  }

  // Cek rate limit sebelum mencoba login
  const { allowed, retryAfter } = checkRateLimit(email)

  if (!allowed) {
    throw new ApiError(429, {
      message: `Terlalu banyak upaya login. Silakan coba lagi setelah ${retryAfter} detik.`,
      errors: {
        email: [`Terlalu banyak upaya login. Silakan coba lagi setelah ${retryAfter} detik.`],
      },
    })
  }

  try {
    const response = await apiPost('/login', credentials)
    // Reset rate limit setelah login berhasil
    resetRateLimit(email)
    // If the login response contains user data, use it (payload penuh:
    // role & organizations ikut tersimpan — jangan hanya data user).
    if (response.data) {
      applyPayload(response)
    } else {
      // Otherwise, fetch user data
      await refreshMe()
    }
  } catch (error) {
    // Catat upaya gagal hanya jika error bukan karena rate limit dari backend
    if (!(error instanceof ApiError && error.status === 429)) {
      recordFailedAttempt(email)
    }
    throw error
  }
}

/** Muat ulang /me setelah data profil berubah. Tanpa reset: state tetap terisi. */
export async function refreshMe() {
  applyPayload(await apiGet('/me'))
}

export async function register(form) {
  return apiPost('/register', form)
}

export async function logout() {
  try {
    await apiPost('/logout')
  } finally {
    reset()
  }
}

export async function switchOrganization(organizationId) {
  const payload = await apiPost('/organization/switch', {
    organization_id: organizationId,
  })
  auth.activeOrganizationId = payload.active_organization_id
  auth.role = payload.role
  return payload
}
