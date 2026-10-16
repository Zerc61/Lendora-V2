/*
 * Navigasi per role. Entri hanya tampil bila route-nya benar-benar
 * terdaftar (R-24: tidak ada tautan ke halaman yang belum ada), sehingga
 * halaman yang menyusul (mis. Audit) otomatis muncul begitu rutenya ada.
 * Ikon dipakai AdminShell (sidebar admin/operasional); borrower dan pelanggan
 * tetap navbar tanpa ikon.
 *
 * `customer` adalah akun tanpa membership aktif: cukup punya akun untuk menyewa
 * dari penyedia mana pun, jadi menunya halaman publik dan booking sendiri.
 */
import {
  ArrowLeftRight,
  BarChart3,
  Boxes,
  ClipboardCheck,
  House,
  History,
  LayoutDashboard,
  Plus,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  TriangleAlert,
  UserRound,
  Users,
  Wallet,
  Wrench,
} from '@lucide/vue'

export const NAV_BY_ROLE = {
  customer: [
    { name: 'home', label: 'Beranda', icon: House },
    { name: 'explore', label: 'Jelajah', icon: Search },
    { name: 'bookings', label: 'Booking saya', icon: ArrowLeftRight },
    { name: 'wallet', label: 'Dompet', icon: Wallet },
    { name: 'profile', label: 'Profil', icon: UserRound },
  ],
  borrower: [
    { name: 'home', label: 'Beranda', icon: House },
    { name: 'catalog', label: 'Cari aset', icon: Search },
    { name: 'loan-create', label: 'Pinjam Baru', icon: Plus },
    { name: 'loans', label: 'Pinjaman saya', icon: ArrowLeftRight },
    { name: 'wallet', label: 'Dompet', icon: Wallet },
    { name: 'issues', label: 'Laporan saya', icon: TriangleAlert },
  ],
  operator: [
    { name: 'home', label: 'Hari ini', icon: LayoutDashboard },
    { name: 'operations', label: 'Operasi', icon: ClipboardCheck },
    { name: 'loans', label: 'Peminjaman', icon: ArrowLeftRight },
    { name: 'assets', label: 'Aset', icon: Boxes },
    { name: 'maintenance', label: 'Perawatan', icon: Wrench },
    { name: 'issues', label: 'Laporan', icon: TriangleAlert },
  ],
  admin: [
    { name: 'home', label: 'Ringkasan', icon: LayoutDashboard },
    { name: 'assets', label: 'Aset', icon: Boxes },
    { name: 'operations', label: 'Operasi', icon: ClipboardCheck },
    { name: 'maintenance', label: 'Perawatan', icon: Wrench },
    { name: 'reports', label: 'Laporan', icon: BarChart3 },
    { name: 'members', label: 'Anggota', icon: Users },
    { name: 'policy', label: 'Kebijakan', icon: SlidersHorizontal },
    { name: 'audit', label: 'Audit', icon: History },
  ],
}

/*
 * Pintu konsol platform. Hanya ditambahkan bila akun memegang peran platform;
 * otorisasi sebenarnya tetap diperiksa server pada setiap aksi platform.
 */
const PLATFORM_ITEM = { name: 'platform-users', label: 'Platform', icon: ShieldCheck }

/*
 * Menu untuk satu peran. `role` null berarti akun tanpa membership aktif.
 * Admin platform mendapat satu entri tambahan, apa pun peran membership-nya.
 */
export function navForRole(role, user) {
  const items = NAV_BY_ROLE[role ?? 'customer'] ?? []

  return user?.platform_role ? [...items, PLATFORM_ITEM] : items
}
