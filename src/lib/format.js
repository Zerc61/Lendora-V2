/*
 * Format tanggal, waktu, dan harga untuk seluruh UI (lokal id-ID).
 * Helper tunggal supaya format konsisten di semua slice.
 */

const dateFmt = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' })
const dateTimeFmt = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
})
const priceFmt = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

function wrap(value, formatter) {
  if (!value) {
    return null
  }
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : formatter.format(date)
}

export function fmtDate(value) {
  return wrap(value, dateFmt)
}

export function fmtDateTime(value) {
  return wrap(value, dateTimeFmt)
}

/*
 * Waktu relatif untuk daftar singkat (notifikasi, aktivitas). Di luar 7 hari
 * jatuh ke tanggal lengkap supaya pembaca tidak menebak-nebak "3 bulan lalu".
 */
const relativeFmt = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' })
const RELATIVE_UNITS = [
  ['day', 86400000],
  ['hour', 3600000],
  ['minute', 60000],
]

export function fmtRelative(value) {
  if (!value) {
    return null
  }
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return null
  }

  const diff = date.getTime() - Date.now()

  if (Math.abs(diff) > 7 * 86400000) {
    return dateFmt.format(date)
  }

  for (const [unit, ms] of RELATIVE_UNITS) {
    if (Math.abs(diff) >= ms || unit === 'minute') {
      return relativeFmt.format(Math.round(diff / ms), unit)
    }
  }

  return dateFmt.format(date)
}

export function fmtPrice(value) {
  if (value == null) {
    return null
  }
  return priceFmt.format(Number(value))
}

export const LOAN_TONES = {
  requested: 'info',
  approved: 'ok',
  rejected: 'err',
  cancelled: 'neutral',
  expired: 'warn',
  checked_out: 'info',
  returned: 'neutral',
}

/*
 * Nada visual status pembayaran booking. Labelnya tetap dari API
 * (`payment_status_label`), sama seperti status dompet.
 */
export const PAYMENT_TONES = {
  pending: 'warn',
  paid: 'ok',
  expired: 'neutral',
  failed: 'err',
  refunded: 'warn',
}

/*
 * Warna status dompet. Labelnya tetap dari API (`status_label`); di sini hanya
 * nada visualnya, supaya badge tidak menebak arti status.
 */
export const WALLET_TONES = {
  pending: 'warn',
  success: 'ok',
  failed: 'err',
  reversed: 'neutral',
}

export const ISSUE_TONES = {
  open: 'warn',
  reviewing: 'info',
  resolved: 'ok',
  dismissed: 'neutral',
}

export const TICKET_TONES = {
  open: 'warn',
  assigned: 'info',
  in_progress: 'info',
  awaiting_parts: 'warn',
  awaiting_verification: 'info',
  resolved: 'ok',
  cancelled: 'neutral',
}

export const LOAN_STATUS_LABELS = {
  requested: 'Menunggu persetujuan',
  approved: 'Disetujui, menunggu diambil',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
  expired: 'Kedaluwarsa',
  checked_out: 'Sedang dipinjam',
  returned: 'Sudah dikembalikan',
}

export const LIFECYCLE_LABELS = {
  active: 'Tersedia',
  awaiting_inspection: 'Menunggu pemeriksaan',
  under_maintenance: 'Dalam perbaikan',
  retired: 'Tidak dipakai lagi',
}

export const CONDITION_LABELS = {
  excellent: 'Baik sekali',
  good: 'Baik',
  fair: 'Cukup',
  poor: 'Buruk',
  unserviceable: 'Tidak bisa dipakai',
}

/* Opsi select diturunkan dari label di atas — satu sumber kebenaran. */
export const CONDITION_OPTIONS = Object.entries(CONDITION_LABELS).map(([value, label]) => ({
  value,
  label,
}))

export const LIFECYCLE_OPTIONS = Object.entries(LIFECYCLE_LABELS).map(([value, label]) => ({
  value,
  label,
}))
