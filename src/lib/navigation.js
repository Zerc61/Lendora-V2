/*
 * Navigasi per role. Entri hanya tampil bila route-nya benar-benar
 * terdaftar (R-24: tidak ada tautan ke halaman yang belum ada), sehingga
 * halaman yang menyusul (mis. Audit) otomatis muncul begitu rutenya ada.
 * Ikon dipakai AdminShell (sidebar admin/operasional); borrower tetap
 * navbar tanpa ikon.
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
  SlidersHorizontal,
  TriangleAlert,
  Users,
  Wrench,
} from '@lucide/vue'

export const NAV_BY_ROLE = {
  borrower: [
    { name: 'home', label: 'Beranda', icon: House },
    { name: 'catalog', label: 'Cari aset', icon: Search },
    { name: 'loan-create', label: 'Pinjam Baru', icon: Plus },
    { name: 'loans', label: 'Pinjaman saya', icon: ArrowLeftRight },
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
