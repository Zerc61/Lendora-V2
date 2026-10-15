<script setup>
/*
 * Beranda per role. Tiga komposisi berbeda dalam satu bahasa visual editorial:
 * admin → ringkasan organisasi dengan angka menghitung; operator → antrean
 * hari ini sebagai garis waktu yang terisi mengikuti gulir; borrower →
 * dashboard peminjaman (angka, kalender, notifikasi, katalog) lewat
 * BorrowerDashboard.vue.
 *
 * Data dan logika muat-turun tidak berubah; hanya presentasi dan gerak yang
 * ditambahkan (lihat lib/motion.js).
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { RotateCcw, Wrench } from '@lucide/vue'
import { auth } from '../lib/auth'
import { apiGet, ApiError } from '../lib/http'
import { roleLabel } from '../lib/roles'
import { CONDITION_LABELS, fmtDateTime, LIFECYCLE_LABELS } from '../lib/format'
import { gsap } from 'gsap'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import BorrowerDashboard from '../components/BorrowerDashboard.vue'
import PageHead from '../components/ui/PageHead.vue'
import StatGrid from '../components/ui/StatGrid.vue'

const today = ref(null)
const overview = ref(null)
const loading = ref(false)
const error = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const activeOrg = computed(
  () => auth.organizations.find((org) => org.id === auth.activeOrganizationId) ?? null,
)

const isAdmin = computed(() => auth.role === 'admin')
const isOperator = computed(() => auth.role === 'operator')

const dateFmt = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
})

function fmt(iso) {
  if (!iso) {
    return ''
  }
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : dateFmt.format(date)
}

async function loadToday() {
  if (!activeOrg.value) {
    return
  }
  loading.value = true
  error.value = null
  try {
    today.value = await apiGet('/operator/today')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat antrean hari ini.'
  } finally {
    loading.value = false
  }
}

async function loadOverview() {
  if (!activeOrg.value) {
    return
  }
  loading.value = true
  error.value = null
  try {
    overview.value = await apiGet('/admin/overview')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat ringkasan.'
  } finally {
    loading.value = false
  }
}

function reload() {
  if (isAdmin.value) {
    loadOverview()
  } else if (isOperator.value) {
    loadToday()
  }
}

onMounted(async () => {
  await reload()
  motion.run(() => {
    gsap.from('.home__marquee', { autoAlpha: 0, y: 8, duration: 0.4, ease: 'power3.out' })
  })
  motion.reveal('.queue', { stagger: 0.1, start: 'top 90%' })
  motion.scrubProgress('.queue-rail__fill', '.queues', { start: 'top 78%', end: 'bottom 60%' })
  motion.scrubProgress('.timeline__fill', '.timeline', { start: 'top 76%', end: 'bottom 58%' })
  motion.refresh()
})

const lifecycleEntries = computed(() => {
  const byLifecycle = overview.value?.assets?.by_lifecycle ?? {}
  return Object.entries(byLifecycle).filter(([, count]) => count > 0)
})

const conditionEntries = computed(() => {
  const byCondition = overview.value?.assets?.by_condition ?? {}
  return Object.entries(byCondition).filter(([, count]) => count > 0)
})

const adminStats = computed(() => {
  if (!overview.value) return []
  const { assets, loans, maintenance, members } = overview.value
  return [
    { value: assets.total, label: 'Total aset', meta: 'INVENTARIS', to: { name: 'assets' } },
    {
      value: assets.problematic,
      label: 'Aset bermasalah',
      meta: 'PERLU ATENSI',
      to: { name: 'assets' },
    },
    {
      value: loans.requested + loans.approved + loans.checked_out,
      label: 'Pinjaman aktif',
      meta: 'BERJALAN',
      to: { name: 'loans', query: { group: 'active' } },
    },
    { value: loans.overdue, label: 'Terlambat', meta: 'LEWAT JADWAL', to: { name: 'loans' } },
    {
      value: maintenance.open_tickets,
      label: 'Tiket terbuka',
      meta: 'PERAWATAN',
      to: { name: 'maintenance', query: { status: 'open' } },
    },
    {
      value: maintenance.open_issues,
      label: 'Laporan terbuka',
      meta: 'INSIDEN',
      to: { name: 'issues', query: { status: 'open' } },
    },
    {
      value: members.active_total,
      label: 'Anggota aktif',
      meta: 'ORANG',
      to: { name: 'members', query: { status: 'active' } },
    },
  ]
})

const operatorStats = computed(() => {
  if (!today.value) return []
  return groups.map((group) => ({
    value: today.value.counts[group.key] ?? 0,
    label: group.title,
    meta: group.meta,
  }))
})

const groups = [
  {
    key: 'awaiting_pickup',
    title: 'Menunggu diambil',
    meta: 'SERAH TERIMA',
    primary: (item) => item.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (item) => fmt(item.starts_at),
    to: (item) => ({ name: 'loan-detail', params: { id: item.id } }),
  },
  {
    key: 'due_today',
    title: 'Jatuh tempo hari ini',
    meta: 'PENGEMBALIAN',
    primary: (item) => item.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (item) => fmt(item.due_at),
    to: (item) => ({ name: 'loan-detail', params: { id: item.id } }),
  },
  {
    key: 'overdue',
    title: 'Terlambat',
    meta: 'TINDAK LANJUT',
    primary: (item) => item.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (item) => fmt(item.due_at),
    to: (item) => ({ name: 'loan-detail', params: { id: item.id } }),
  },
  {
    key: 'awaiting_inspection',
    title: 'Menunggu inspeksi',
    meta: 'KONDISI ASET',
    primary: (item) => `${item.code}, ${item.name}`,
    secondary: (item) => item.condition_label ?? '',
    to: (item) => ({ name: 'asset-detail', params: { id: item.id } }),
  },
]

const marqueeItems = [
  'KATALOG ASET',
  'PERMINTAAN',
  'SERAH TERIMA',
  'INSPEKSI KONDISI',
  'PERAWATAN',
  'LAPORAN',
]
</script>

<template>
  <div ref="page" class="home">
    <PageHead :label="roleLabel(auth.role)" :title="`Selamat datang, ${auth.user?.name}`">
      <template v-if="activeOrg">
        Kamu masuk sebagai <strong>{{ roleLabel(auth.role) }}</strong> di organisasi
        <strong>{{ activeOrg.name }}</strong
        >.
      </template>
      <template v-else> Akun ini belum terhubung ke organisasi mana pun. </template>
    </PageHead>

    <div class="home__marquee marquee" aria-hidden="true">
      <div class="marquee__track">
        <template v-for="repeat in 2" :key="repeat">
          <span v-for="item in marqueeItems" :key="`${repeat}-${item}`">{{ item }}</span>
          <span>LENDORA V2</span>
        </template>
      </div>
    </div>

    <EmptyState
      v-if="!activeOrg"
      title="Belum ada organisasi aktif"
      hint="Minta admin mengundangmu sehingga akun ini bisa memakai Lendora."
    />

    <template v-else>
      <section v-if="isAdmin" aria-labelledby="ringkasan-title" class="home__section">
        <div class="home__section-head">
          <p class="index-label">RINGKASAN</p>
          <h2 id="ringkasan-title" class="home__section-title">Angka organisasi hari ini.</h2>
        </div>

        <LoadingState v-if="loading" label="Memuat ringkasan" />

        <ErrorState v-else-if="error" :message="error" @retry="reload" />

        <template v-else-if="overview">
          <StatGrid :items="adminStats" />

          <div class="queues">
            <span class="queue-rail" aria-hidden="true"
              ><span class="queue-rail__fill"></span
            ></span>

            <section class="queue">
              <h3 class="queue__title">Aset per status</h3>
              <ul class="queue__list">
                <li v-for="[key, count] in lifecycleEntries" :key="key" class="queue__row">
                  <span class="queue__primary">{{ LIFECYCLE_LABELS[key] ?? key }}</span>
                  <span class="queue__rule" aria-hidden="true"></span>
                  <span class="queue__secondary num">{{ count }}</span>
                </li>
              </ul>
              <p v-if="!lifecycleEntries.length" class="queue__empty">Tidak ada.</p>
            </section>

            <section class="queue">
              <h3 class="queue__title">Aset per kondisi</h3>
              <ul class="queue__list">
                <li v-for="[key, count] in conditionEntries" :key="key" class="queue__row">
                  <span class="queue__primary">{{ CONDITION_LABELS[key] ?? key }}</span>
                  <span class="queue__rule" aria-hidden="true"></span>
                  <span class="queue__secondary num">{{ count }}</span>
                </li>
              </ul>
              <p v-if="!conditionEntries.length" class="queue__empty">Tidak ada.</p>
            </section>
          </div>

          <p class="more-link">
            <RouterLink :to="{ name: 'reports' }" class="link-sweep">
              Lihat laporan lengkap
            </RouterLink>
          </p>
        </template>
      </section>

      <section v-else-if="isOperator" aria-labelledby="antrean-title" class="home__section">
        <div class="home__section-head">
          <p class="index-label">ANTREAN</p>
          <h2 id="antrean-title" class="home__section-title">Yang harus diselesaikan hari ini.</h2>
        </div>

        <LoadingState v-if="loading" label="Memuat antrean" />

        <ErrorState v-else-if="error" :message="error" @retry="reload" />

        <template v-else-if="today">
          <StatGrid :items="operatorStats" />

          <div class="timeline">
            <span class="timeline__rail" aria-hidden="true"
              ><span class="timeline__fill"></span
            ></span>

            <article v-for="group in groups" :key="group.key" class="timeline__step">
              <span class="timeline__node" aria-hidden="true"></span>
              <div class="timeline__body">
                <p class="timeline__meta">{{ group.meta }}</p>
                <h3 class="timeline__title">{{ group.title }}</h3>
                <ul v-if="today[group.key]?.length" class="timeline__list">
                  <li v-for="item in today[group.key].slice(0, 6)" :key="item.id">
                    <RouterLink :to="group.to(item)" class="timeline__item">
                      <span class="timeline__item-primary">{{ group.primary(item) }}</span>
                      <span class="timeline__item-secondary num">{{ group.secondary(item) }}</span>
                    </RouterLink>
                  </li>
                </ul>
                <p v-if="today[group.key]?.length > 6" class="queue__more">
                  <RouterLink :to="{ name: 'operations' }" class="link-sweep">
                    Lihat semua di papan operasi
                  </RouterLink>
                </p>
                <p v-else-if="!today[group.key]?.length" class="queue__empty">
                  Tidak ada antrean pada kelompok ini.
                </p>
              </div>
            </article>

            <aside class="timeline__aside">
              <span class="timeline__aside-symbol" aria-hidden="true">
                <RotateCcw :size="16" />
              </span>
              <p>
                <strong>Setelah pengembalian</strong><br />
                Aset masuk antrean inspeksi sebelum kembali tersedia. Temuan dapat ditindaklanjuti
                lewat tiket perawatan.
              </p>
              <Wrench :size="15" aria-hidden="true" />
            </aside>
          </div>

          <p class="more-link">
            <RouterLink :to="{ name: 'operations' }" class="link-sweep">
              Buka papan antrean operasi
            </RouterLink>
          </p>
        </template>
      </section>

      <BorrowerDashboard v-else />
    </template>
  </div>
</template>

<style scoped>
.home {
  display: grid;
  gap: 2.5rem;
}

.home__marquee {
  margin-inline: calc(var(--ln-gutter) * -1);
}

.home__section {
  display: grid;
  gap: 1.5rem;
}

.home__section-head {
  max-width: 46rem;
}

.home__section-title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

/* Antrean: daftar baris dengan garis penghubung dan rail progres. */
.queues {
  position: relative;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2.5rem;
  padding-left: 2rem;
}

.queue-rail,
.timeline__rail {
  position: absolute;
  width: 1px;
  background: var(--ln-border);
}

.queue-rail {
  top: 0;
  bottom: 0;
  left: 0;
}

.queue-rail__fill,
.timeline__fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
}

.queue__title {
  margin-bottom: 0.75rem;
  padding-top: 0.75rem;
  border-top: 2px solid var(--ln-border-strong);
  font-size: 1rem;
}

.queue__list {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.queue__row {
  display: grid;
  grid-template-columns: auto minmax(1.5rem, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  min-height: 3rem;
  border-bottom: 1px solid var(--ln-border);
}

.queue__primary {
  font-weight: 600;
}

.queue__rule {
  height: 1px;
  background: var(--ln-border);
}

.queue__secondary {
  color: var(--ln-text-2);
  font-size: 0.875rem;
}

.queue__more,
.queue__empty {
  margin: 0.75rem 0 0;
  color: var(--ln-text-2);
  font-size: 0.875rem;
}

.more-link {
  margin: 0;
}

/* Garis waktu antrean operator. */
.timeline {
  position: relative;
  display: grid;
  gap: 2rem;
  padding-left: 2.5rem;
}

.timeline__rail {
  top: 0.5rem;
  bottom: 5rem;
  left: 0.5rem;
}

.timeline__step {
  position: relative;
  display: grid;
}

.timeline__node {
  position: absolute;
  top: 0.375rem;
  left: -2.5rem;
  width: 11px;
  height: 11px;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-bg);
}

.timeline__body {
  display: grid;
  gap: 0.375rem;
}

.timeline__meta {
  margin: 0;
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.timeline__title {
  margin: 0;
  font-size: 1.25rem;
}

.timeline__list {
  margin: 0.5rem 0 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.timeline__list li {
  border-bottom: 1px solid var(--ln-border);
}

.timeline__item {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.625rem 0;
  color: var(--ln-text);
  text-decoration: none;
}

.timeline__item:hover .timeline__item-primary {
  text-decoration: underline;
  text-underline-offset: 3px;
}

.timeline__item-primary {
  font-weight: 600;
}

.timeline__item-secondary {
  color: var(--ln-text-2);
  font-size: 0.875rem;
  text-align: right;
}

.timeline__aside {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 0.875rem;
  margin-left: 2.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--ln-border-strong);
  color: var(--ln-text-2);
}

.timeline__aside-symbol {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border: 1px solid var(--ln-border-strong);
  color: var(--ln-text);
}

.timeline__aside p {
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.7;
}

.timeline__aside strong {
  color: var(--ln-text);
}

@media (max-width: 48rem) {
  .queues {
    grid-template-columns: 1fr;
  }

  .queues {
    gap: 2rem;
    padding-left: 1.5rem;
  }

  .timeline {
    padding-left: 2rem;
  }

  .timeline__node {
    left: -2rem;
  }

  .timeline__aside {
    margin-left: 0;
  }
}
</style>
