/*
 * Snap.js Midtrans: halaman pembayaran terbuka di dalam SPA, bukan pindah
 * halaman.
 *
 * Skrip pihak ketiga disisipkan saat dibutuhkan, bukan di `index.html`, supaya
 * halaman lain tidak ikut memuatnya. Kalau skrip gagal dimuat (CDN diblokir,
 * jaringan bermasalah), pemanggil memakai `redirect_url` sebagai jalur cadangan —
 * jadi pembayaran tidak pernah gagal hanya karena Snap tidak bisa dimuat.
 *
 * Kunci klien memang publik (tercetak di skrip ini di browser); kunci server
 * hanya ada di backend.
 */

const SCRIPT_ID = 'midtrans-snap'
const SANDBOX_SCRIPT = 'https://app.sandbox.midtrans.com/snap/snap.js'
const PRODUCTION_SCRIPT = 'https://app.midtrans.com/snap/snap.js'

/**
 * Alamat skrip Snap untuk lingkungan yang dipakai.
 *
 * @param {boolean} isProduction
 */
export function snapScriptUrl(isProduction) {
  return isProduction ? PRODUCTION_SCRIPT : SANDBOX_SCRIPT
}

function loadSnapScript({ clientKey, isProduction }) {
  const existing = document.getElementById(SCRIPT_ID)

  if (existing) {
    if (existing.dataset.clientKey === clientKey) {
      return Promise.resolve(window.snap)
    }

    // Lingkungan berpindah (sandbox ↔ production): skrip lama dibuang.
    existing.remove()
    delete window.snap
  }

  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.id = SCRIPT_ID
    script.src = snapScriptUrl(isProduction)
    script.dataset.clientKey = clientKey
    script.async = true
    script.onload = () =>
      window.snap ? resolve(window.snap) : reject(new Error('Snap tidak tersedia setelah dimuat.'))
    script.onerror = () => reject(new Error('Skrip Snap gagal dimuat.'))
    document.head.appendChild(script)
  })
}

/**
 * Buka halaman pembayaran Snap di dalam halaman ini.
 *
 * `onSettled` dipanggil saat Midtrans melaporkan hasil apa pun (berhasil,
 * menunggu, gagal) supaya pemanggil memuat ulang data. Saldo tidak pernah
 * ditambah dari sisi klien: yang menentukan saldo adalah notifikasi ke server.
 *
 * @param {{token?: string|null, clientKey?: string|null, isProduction?: boolean, onSettled?: () => void}} options
 * @returns {Promise<boolean>} `true` bila Snap terbuka; `false` bila pemanggil
 *   harus memakai `redirect_url`.
 */
export async function payWithSnap({ token, clientKey, isProduction = false, onSettled }) {
  if (!token || !clientKey) {
    return false
  }

  const settled = () => {
    if (typeof onSettled === 'function') {
      onSettled()
    }
  }

  try {
    const snap = await loadSnapScript({ clientKey, isProduction })

    snap.pay(token, {
      onSuccess: settled,
      onPending: settled,
      onError: settled,
      onClose: () => {},
    })

    return true
  } catch {
    return false
  }
}
