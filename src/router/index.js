import { createRouter, createWebHistory } from 'vue-router'
import { auth, bootstrap } from '../lib/auth'

/*
 * Rute aplikasi Lendora.
 *
 * Struktur:
 *   /         → Landing page publik (tidak butuh auth)
 *   /login    → Login
 *   /register → Register
 *   /app      → ShellHost (butuh auth) — navbar AppShell untuk borrower dan
 *               pelanggan, sidebar AdminShell untuk admin & operasional
 *   /platform → Konsol admin platform (butuh peran platform, tanpa membership)
 *
 * Guard di bawah menjamin:
 *   - Publik: '/', '/login', '/register' — bisa diakses tanpa auth
 *   - Protected: '/app/*' — butuh autentikasi, redirect ke /login
 *   - '/platform/*' — butuh autentikasi dan peran platform
 *   - Setelah login redirect ke '/app' bukan '/'
 */

const routes = [
  // ──────────────────────────────────────────────────────────────────────
  // PUBLIK
  // ──────────────────────────────────────────────────────────────────────
  {
    path: '/',
    name: 'landing',
    component: () => import('../views/landing/LandingView.vue'),
    meta: { public: true, title: 'Sewa Alat dan Kamera' },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true, title: 'Masuk' },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('../views/RegisterView.vue'),
    meta: { public: true, title: 'Daftar' },
  },
  {
    path: '/jelajah',
    name: 'explore',
    component: () => import('../views/ExploreView.vue'),
    meta: { public: true, title: 'Jelajah penyedia' },
  },
  {
    path: '/jelajah/:slug',
    name: 'explore-provider',
    component: () => import('../views/ExploreProviderView.vue'),
    meta: { public: true, title: 'Profil penyedia' },
  },
  {
    // Customer cukup punya akun, tidak perlu membership, jadi riwayat
    // pemesanannya sengaja tidak berada di bawah /app (shell butuh membership).
    path: '/booking',
    name: 'bookings',
    component: () => import('../views/BookingsView.vue'),
    meta: { requiresAuth: true, title: 'Booking saya' },
  },

  // ──────────────────────────────────────────────────────────────────────
  // PROTECTED — ShellHost di /app (shell dipilih per role)
  // ──────────────────────────────────────────────────────────────────────
  {
    path: '/app',
    component: () => import('../layouts/ShellHost.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'home',
        component: () => import('../views/HomeView.vue'),
        meta: { title: 'Beranda' },
      },
      {
        path: 'catalog',
        name: 'catalog',
        component: () => import('../views/CatalogView.vue'),
        meta: { title: 'Cari aset' },
      },
      {
        path: 'assets',
        name: 'assets',
        component: () => import('../views/AssetsView.vue'),
        meta: { title: 'Aset', roles: ['operator', 'admin'] },
      },
      {
        path: 'assets/:id(\\d+)',
        name: 'asset-detail',
        component: () => import('../views/AssetDetailView.vue'),
        meta: { title: 'Detail aset' },
      },
      {
        path: 'loan',
        name: 'loan-create',
        component: () => import('../views/LoanCreateView.vue'),
        meta: { title: 'Minta pinjam' },
      },
      {
        path: 'loans',
        name: 'loans',
        component: () => import('../views/LoansView.vue'),
        meta: { title: 'Peminjaman' },
      },
      {
        path: 'loans/:id(\\d+)',
        name: 'loan-detail',
        component: () => import('../views/LoanDetailView.vue'),
        meta: { title: 'Detail peminjaman' },
      },
      {
        path: 'operations',
        name: 'operations',
        component: () => import('../views/OperationsView.vue'),
        meta: { title: 'Operasi', roles: ['operator', 'admin'] },
      },
      {
        path: 'loans/:id(\\d+)/checkout',
        name: 'loan-checkout',
        component: () => import('../views/LoanCheckoutView.vue'),
        meta: { title: 'Serah terima', roles: ['operator', 'admin'] },
      },
      {
        path: 'loans/:id(\\d+)/checkin',
        name: 'loan-checkin',
        component: () => import('../views/LoanCheckinView.vue'),
        meta: { title: 'Terima kembali', roles: ['operator', 'admin'] },
      },
      {
        path: 'issue',
        name: 'issue-create',
        component: () => import('../views/IssueCreateView.vue'),
        meta: { title: 'Laporkan masalah' },
      },
      {
        path: 'issues',
        name: 'issues',
        component: () => import('../views/IssuesView.vue'),
        meta: { title: 'Laporan masalah' },
      },
      {
        path: 'issues/:id(\\d+)',
        name: 'issue-detail',
        component: () => import('../views/IssueDetailView.vue'),
        meta: { title: 'Detail laporan' },
      },
      {
        path: 'maintenance/new',
        name: 'ticket-create',
        component: () => import('../views/TicketCreateView.vue'),
        meta: { title: 'Buat tiket perawatan', roles: ['operator', 'admin'] },
      },
      {
        path: 'maintenance',
        name: 'maintenance',
        component: () => import('../views/MaintenanceView.vue'),
        meta: { title: 'Perawatan', roles: ['operator', 'admin'] },
      },
      {
        path: 'maintenance/:id(\\d+)',
        name: 'ticket-detail',
        component: () => import('../views/TicketDetailView.vue'),
        meta: { title: 'Detail tiket', roles: ['operator', 'admin'] },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: { title: 'Laporan', roles: ['admin'] },
      },
      {
        path: 'members',
        name: 'members',
        component: () => import('../views/MembersView.vue'),
        meta: { title: 'Anggota', roles: ['admin'] },
      },
      {
        path: 'policy',
        name: 'policy',
        component: () => import('../views/PolicyView.vue'),
        meta: { title: 'Kebijakan', roles: ['admin'] },
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('../views/ProfileView.vue'),
        meta: { title: 'Profil' },
      },
      {
        path: 'wallet',
        name: 'wallet',
        component: () => import('../views/WalletView.vue'),
        meta: { title: 'Dompet' },
      },
      {
        path: 'audit',
        name: 'audit',
        component: () => import('../views/AuditView.vue'),
        meta: { title: 'Audit', roles: ['admin'] },
      },
    ],
  },

  // ──────────────────────────────────────────────────────────────────────
  // KONSOL PLATFORM — di luar /app: admin platform bekerja lintas organisasi
  // dan tidak memerlukan membership.
  // ──────────────────────────────────────────────────────────────────────
  {
    path: '/platform',
    component: () => import('../layouts/PlatformShell.vue'),
    meta: { requiresAuth: true, platform: true },
    children: [
      {
        path: '',
        name: 'platform-users',
        component: () => import('../views/platform/PlatformUsersView.vue'),
        meta: { title: 'Pengguna platform' },
      },
      {
        path: 'penyedia',
        name: 'platform-providers',
        component: () => import('../views/platform/PlatformProvidersView.vue'),
        meta: { title: 'Verifikasi penyedia' },
      },
      {
        path: 'dompet',
        name: 'platform-wallet',
        component: () => import('../views/platform/PlatformWalletView.vue'),
        meta: { title: 'Transaksi dompet' },
      },
    ],
  },

  // ──────────────────────────────────────────────────────────────────────
  // FALLBACK — redirect ke landing
  // ──────────────────────────────────────────────────────────────────────
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    return { top: 0 }
  },
})

router.beforeEach(async (to) => {
  if (!auth.ready) {
    await bootstrap()
  }

  // Rute publik (landing, login, register)
  if (to.meta.public) {
    // Jika sudah login, redirect dari login/register ke /app
    if (auth.user && (to.name === 'login' || to.name === 'register')) {
      return { name: 'home' }
    }
    return true
  }

  // Rute protected — butuh auth
  if (!auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Cek role bila route punya batasan role
  if (to.meta.roles && !to.meta.roles.includes(auth.role)) {
    return { name: 'home' }
  }

  // Konsol platform hanya untuk akun yang memegang peran platform.
  if (to.meta.platform && !auth.user?.platform_role) {
    return { name: 'home' }
  }

  return true
})

router.afterEach((to) => {
  const base = 'Lendora'
  document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
})

export default router
