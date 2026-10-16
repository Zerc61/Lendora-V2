/*
 * Label role untuk ditampilkan di UI. Sumber label otoritatif ada di backend
 * (Membership::ROLE_LABELS). Peta kosmetik ini hanya untuk tampilan.
 *
 * `customer` adalah akun tanpa membership aktif; backend mengirim `role` null
 * untuk akun seperti itu, jadi labelnya dipilih di sini.
 */

export const ROLE_LABELS = {
  customer: 'Pelanggan',
  borrower: 'Peminjam',
  operator: 'Operator',
  admin: 'Admin',
}

export function roleLabel(role) {
  return ROLE_LABELS[role ?? 'customer'] ?? role ?? null
}
