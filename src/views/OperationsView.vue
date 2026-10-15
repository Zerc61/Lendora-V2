<script setup>
/*
 * Papan antrean operasi untuk operator/admin: kelompok bernomor (serah
 * terima, pengembalian, tindak lanjut, inspeksi) dengan rail progres yang
 * terisi mengikuti gulir. Reuse endpoint /operator/today; hanya markup,
 * style, dan gerak yang berubah.
 */
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { fmtDateTime } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHead from '../components/ui/PageHead.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'

const today = ref(null)
const loading = ref(false)
const error = ref(null)

/* Aksi baris antrean: setujui/tolak pengajuan, tutup inspeksi aset. */
const approveTarget = ref(null)
const rejectTarget = ref(null)
const inspectTarget = ref(null)
const actionBusy = ref(false)
const actionError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

async function load() {
  loading.value = true
  error.value = null
  try {
    today.value = await apiGet('/operator/today')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat antrean operasi.'
  } finally {
    loading.value = false
  }
}

/* Susunan blok papan. Hanya presentasi: key menunjuk payload /operator/today. */
const groups = [
  {
    key: 'awaiting_approval',
    meta: 'PERSETUJUAN',
    title: 'Menunggu persetujuan',
    hint: 'Tidak ada pengajuan yang menunggu persetujuan.',
    primary: (row) => row.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (row) => `Mulai ${fmtDateTime(row.starts_at)}`,
    to: (row) => ({ name: 'loan-detail', params: { id: row.id } }),
    action: null,
    rowActions: 'approval',
  },
  {
    key: 'awaiting_pickup',
    meta: 'SERAH TERIMA',
    title: 'Menunggu diambil',
    hint: 'Belum ada peminjaman yang disetujui dan siap diambil.',
    primary: (row) => row.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (row) => `Mulai ${fmtDateTime(row.starts_at)}`,
    to: (row) => ({ name: 'loan-detail', params: { id: row.id } }),
    action: (row) => ({
      label: 'Serah terima',
      primary: true,
      to: { name: 'loan-checkout', params: { id: row.id } },
    }),
  },
  {
    key: 'due_today',
    meta: 'PENGEMBALIAN',
    title: 'Jatuh tempo hari ini',
    hint: 'Tidak ada peminjaman yang jatuh tempo hari ini.',
    primary: (row) => row.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (row) => `Jatuh tempo ${fmtDateTime(row.due_at)}`,
    to: (row) => ({ name: 'loan-detail', params: { id: row.id } }),
    action: (row) => ({
      label: 'Terima kembali',
      primary: false,
      to: { name: 'loan-checkin', params: { id: row.id } },
    }),
  },
  {
    key: 'overdue',
    meta: 'TINDAK LANJUT',
    title: 'Terlambat',
    hint: 'Tidak ada peminjaman yang terlambat.',
    primary: (row) => row.borrower_name ?? 'Borrower tidak tercatat',
    secondary: (row) => `Terlambat sejak ${fmtDateTime(row.due_at)}`,
    to: (row) => ({ name: 'loan-detail', params: { id: row.id } }),
    action: (row) => ({
      label: 'Terima kembali',
      primary: false,
      to: { name: 'loan-checkin', params: { id: row.id } },
    }),
  },
  {
    key: 'awaiting_inspection',
    meta: 'INSPEKSI',
    title: 'Menunggu pemeriksaan',
    hint: 'Tidak ada aset yang menunggu pemeriksaan.',
    primary: (row) => `${row.code}, ${row.name}`,
    secondary: (row) => row.condition_label ?? '',
    to: (row) => ({ name: 'asset-detail', params: { id: row.id } }),
    action: null,
    rowActions: 'inspection',
  },
]

async function runAction(request, close, successMessage) {
  actionBusy.value = true
  actionError.value = null
  try {
    await request()
    close()
    toast.ok(successMessage)
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal menjalankan aksi.'
  } finally {
    actionBusy.value = false
  }
}

function approveLoan(loan) {
  return runAction(
    () => apiPost(`/loans/${loan.id}/approve`),
    () => (approveTarget.value = null),
    'Pengajuan disetujui.',
  )
}

function rejectLoan(loan, reason) {
  return runAction(
    () => apiPost(`/loans/${loan.id}/reject`, { reason }),
    () => (rejectTarget.value = null),
    'Pengajuan ditolak.',
  )
}

function completeInspection(asset, notes) {
  return runAction(
    () => apiPost(`/assets/${asset.id}/inspection`, notes ? { notes } : {}),
    () => (inspectTarget.value = null),
    'Pemeriksaan aset selesai dicatat.',
  )
}

function startMotion() {
  motion.reveal('.board', { stagger: 0.09 })
  motion.scrubProgress('.boards__rail-fill', '.boards', { start: 'top 80%', end: 'bottom 62%' })
  motion.refresh()
}

onMounted(async () => {
  await load()
  if (today.value) {
    startMotion()
  }
})
</script>

<template>
  <section ref="page" class="ops">
    <PageHead
      label="OPERASI"
      title="Papan antrean operasional"
      lede="Antrean serah terima (checkout) dan pengembalian (checkin) hari ini, ditambah aset yang menunggu pemeriksaan kondisi."
    />

    <LoadingState v-if="loading" label="Memuat antrean" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <div v-else-if="today" class="boards">
      <p v-if="actionError" class="form-error" role="alert">{{ actionError }}</p>

      <span class="boards__rail" aria-hidden="true"><span class="boards__rail-fill"></span></span>

      <section
        v-for="group in groups"
        :key="group.key"
        class="board"
        :aria-labelledby="`board-${group.key}-title`"
      >
        <header class="board__head">
          <div class="board__titles">
            <p class="index-label">{{ group.meta }}</p>
            <h2 :id="`board-${group.key}-title`" class="board__title">{{ group.title }}</h2>
          </div>
          <p class="board__count">
            <b class="num">{{ today.counts[group.key] ?? 0 }}</b>
            <span class="board__count-label">antre</span>
          </p>
        </header>

        <EmptyState v-if="!today[group.key]?.length" title="Tidak ada" :hint="group.hint" />

        <ul v-else class="queue">
          <li v-for="row in today[group.key]" :key="row.id" class="queue__row">
            <RouterLink :to="group.to(row)" class="queue__link">
              <span class="queue__main">
                <strong class="queue__primary">{{ group.primary(row) }}</strong>
                <span class="queue__secondary text-2">{{ group.secondary(row) }}</span>
              </span>
            </RouterLink>
            <RouterLink
              v-if="group.action"
              :to="group.action(row).to"
              class="btn queue__action"
              :class="{ 'btn--primary': group.action(row).primary }"
            >
              {{ group.action(row).label }}
            </RouterLink>

            <span v-if="group.rowActions === 'approval'" class="queue__actions">
              <button type="button" class="btn btn--primary" @click="approveTarget = row">
                Setujui
              </button>
              <button type="button" class="btn" @click="rejectTarget = row">Tolak</button>
            </span>
            <button
              v-else-if="group.rowActions === 'inspection'"
              type="button"
              class="btn queue__action"
              @click="inspectTarget = row"
            >
              Catat hasil
            </button>
          </li>
        </ul>
      </section>
    </div>

    <ConfirmDialog
      v-if="approveTarget"
      title="Setujui pengajuan ini?"
      :message="`Peminjaman oleh ${approveTarget.borrower_name ?? 'borrower'} disetujui dan masuk antrean serah terima.`"
      confirm-label="Setujui"
      :busy="actionBusy"
      @confirm="approveLoan(approveTarget)"
      @cancel="approveTarget = null"
    />

    <ConfirmDialog
      v-if="rejectTarget"
      title="Tolak pengajuan?"
      :message="`Peminjaman oleh ${rejectTarget.borrower_name ?? 'borrower'} akan ditolak dan borrower menerima alasan ini.`"
      confirm-label="Tolak pengajuan"
      reason-label="Alasan penolakan"
      require-reason
      :busy="actionBusy"
      @confirm="(reason) => rejectLoan(rejectTarget, reason)"
      @cancel="rejectTarget = null"
    />

    <ConfirmDialog
      v-if="inspectTarget"
      title="Selesaikan pemeriksaan?"
      :message="`${inspectTarget.code}, ${inspectTarget.name} ditandai sudah diperiksa; aset dilepas kembali atau dialihkan ke perawatan sesuai kondisi tercatat saat pengembalian.`"
      confirm-label="Tandai selesai"
      reason-label="Catatan pemeriksaan (opsional)"
      :busy="actionBusy"
      @confirm="(notes) => completeInspection(inspectTarget, notes)"
      @cancel="inspectTarget = null"
    />
  </section>
</template>

<style scoped>
.ops {
  display: grid;
  gap: 2.5rem;
}

/* Papan antrean: rail di kiri, isinya blok bernomor. */
.boards {
  position: relative;
  display: grid;
  gap: 2.5rem;
  padding-left: 2.5rem;
}

.boards__rail {
  position: absolute;
  top: 0.5rem;
  bottom: 0.5rem;
  left: 0.5rem;
  width: 1px;
  background: var(--ln-border);
}

.boards__rail-fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
}

.board {
  position: relative;
  display: grid;
  gap: 1rem;
  padding-top: 1.25rem;
  border-top: 2px solid var(--ln-border-strong);
}

/* Node persegi pada rail, sejajar judul blok. */
.board::before {
  position: absolute;
  top: 1.75rem;
  left: -2.5rem;
  width: 11px;
  height: 11px;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-bg);
  content: '';
}

.board__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: 1rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--ln-border);
}

.board__titles .index-label {
  margin-bottom: 0.375rem;
}

.board__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.board__count {
  display: grid;
  justify-items: end;
  gap: 0.25rem;
  margin: 0;
  text-align: right;
}

.board__count b {
  font-family: var(--ln-font-display);
  font-size: clamp(1.75rem, 2.6vw, 2.5rem);
  font-weight: 500;
  letter-spacing: -0.04em;
  line-height: 0.9;
}

.board__count-label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

/* Baris antrean: garis rule, tinggi sentuh 44px. */
.queue {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.queue__row {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.queue__link {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  color: var(--ln-text);
  text-decoration: none;
}

.queue__main {
  display: grid;
  gap: 0.125rem;
}

.queue__primary {
  font-weight: 600;
}

.queue__secondary {
  font-size: 0.875rem;
}

.queue__link:hover .queue__primary {
  text-decoration: underline;
  text-underline-offset: 3px;
}

.queue__action {
  flex-shrink: 0;
}

.queue__actions {
  display: flex;
  flex-shrink: 0;
  gap: 0.5rem;
}

.boards .form-error {
  margin: 0;
}

@media (max-width: 48rem) {
  .boards {
    gap: 2rem;
    padding-left: 1.75rem;
  }

  .board::before {
    left: -1.75rem;
  }

  .board__head {
    grid-template-columns: minmax(0, 1fr);
  }

  .board__count {
    grid-column: 1 / -1;
    justify-items: start;
    text-align: left;
  }

  .queue__row {
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .queue__link {
    flex-basis: 100%;
  }

  .queue__action {
    width: 100%;
  }

  .queue__actions {
    width: 100%;
  }

  .queue__actions .btn {
    flex: 1;
  }
}
</style>
