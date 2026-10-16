/*
 * Aturan dompet yang murni: batas nominal, validasinya, tanda saldo, dan kapan
 * masa berlaku ditampilkan.
 *
 * Dipisah dari komponen supaya bisa diuji tanpa DOM (pola `scripts/check-*.mjs`,
 * dijalankan dengan `node --test`) dan supaya halaman dompet, konsol platform,
 * serta validasi server membaca aturan yang sama.
 */
import { fmtPrice } from './format.js'

/*
 * Cadangan bila server belum mengirim batas (mis. permintaan saldo gagal).
 * Nilai sebenarnya datang dari GET /wallet/balance supaya klien dan server tidak
 * bisa berbeda; ini hanya jaring pengaman agar formulir tetap bisa dipakai.
 */
export const FALLBACK_TOPUP_MIN = 1000
export const FALLBACK_TOPUP_MAX = 1000000

/**
 * Ambil batas top up dari balasan `/wallet/balance`, dengan cadangan.
 *
 * @param {{topup_min?: unknown, topup_max?: unknown}|null} balance
 * @returns {{min: number, max: number}}
 */
export function topUpLimits(balance) {
  const min = Number(balance?.topup_min)
  const max = Number(balance?.topup_max)

  return {
    min: Number.isFinite(min) && min > 0 ? min : FALLBACK_TOPUP_MIN,
    max: Number.isFinite(max) && max > 0 ? max : FALLBACK_TOPUP_MAX,
  }
}

/**
 * @param {unknown} amount
 * @param {{min: number, max: number}} limits
 */
export function isValidTopUpAmount(amount, limits) {
  const value = Number(amount)

  return Number.isInteger(value) && value >= limits.min && value <= limits.max
}

/**
 * Pesan kesalahan pertama untuk nominal, atau `null` bila nominalnya sah.
 * Alasannya dikirim sebagai teks supaya formulir tidak menebak sendiri.
 *
 * @param {unknown} amount
 * @param {{min: number, max: number}} limits
 * @returns {string|null}
 */
export function topUpError(amount, limits) {
  if (amount === '' || amount === null || amount === undefined) {
    return 'Nominal belum diisi.'
  }

  const value = Number(amount)

  if (!Number.isFinite(value) || !Number.isInteger(value)) {
    return 'Nominal harus bilangan bulat rupiah.'
  }

  if (value < limits.min) {
    return `Nominal minimal ${fmtPrice(limits.min)}.`
  }

  if (value > limits.max) {
    return `Nominal maksimal ${fmtPrice(limits.max)}.`
  }

  return null
}

/**
 * Nominal bertanda. Arahnya dari `direction` (jenis transaksi di server), bukan
 * dari tanda nominal, karena nominal di buku besar selalu positif.
 *
 * @param {{direction?: string, amount?: unknown}|null} transaction
 */
export function signedWalletAmount(transaction) {
  const sign = transaction?.direction === 'in' ? '+' : '−'
  const amount = Number(transaction?.amount ?? 0)

  return `${sign} ${fmtPrice(Number.isFinite(amount) ? amount : 0)}`
}

/**
 * Masa berlaku hanya bermakna selama transaksi masih menunggu pembayaran.
 *
 * @param {{status?: string, expires_at?: string|null}|null} transaction
 * @returns {string|null}
 */
export function walletExpiry(transaction) {
  if (transaction?.status !== 'pending' || !transaction?.expires_at) {
    return null
  }

  return transaction.expires_at
}
