/*
 * Label role untuk ditampilkan di UI. Sumber label otoritatif ada di backend
 * (Membership::ROLE_LABELS). Peta kosmetik ini hanya untuk tampilan.
 */

export const ROLE_LABELS = {
  borrower: 'Peminjam',
  operator: 'Operator',
  admin: 'Admin',
}

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? role ?? null
}
