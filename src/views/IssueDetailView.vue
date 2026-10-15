<script setup>
/*
 * Detail laporan masalah. Pelapor melihat status dan hasil; operator/admin
 * dapat meninjau, menyelesaikan (dengan resolusi), atau menolak laporan.
 * Dari laporan yang valid, manajemen dapat membuat tiket perawatan.
 * Logika aksi dan permission tidak berubah; yang baru hanya kepala halaman,
 * garis waktu bernomor, dan gerak.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { auth } from '../lib/auth'
import { fmtDateTime, ISSUE_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const id = Number(route.params.id)

const issue = ref(null)
const loading = ref(true)
const error = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const resolveOpen = ref(false)
const resolution = ref('')
const resolveSending = ref(false)
const resolveError = ref(null)
const reviewError = ref(null)

const dismissOpen = ref(false)
const dismissReason = ref('')
const dismissSending = ref(false)
const dismissError = ref(null)

const isManagement = computed(() => auth.role === 'operator' || auth.role === 'admin')
const canReview = computed(() => isManagement.value && issue.value?.status === 'open')
const canResolve = computed(
  () => isManagement.value && ['open', 'reviewing'].includes(issue.value?.status),
)
const canDismiss = canResolve

/*
 * Garis waktu status: diturunkan dari data laporan yang sudah ada
 * (status, created_at, updated_at, resolved_at) tanpa panggilan API baru.
 */
const timeline = computed(() => {
  const current = issue.value
  if (!current) {
    return []
  }

  const position = { open: 1, reviewing: 2, resolved: 3, dismissed: 3 }[current.status] ?? 1
  const decided = ['resolved', 'dismissed'].includes(current.status)

  const steps = [
    {
      index: '01',
      meta: 'PELAPORAN',
      title: 'Dilaporkan',
      note: `${current.type_label} dengan severity ${current.severity_label.toLowerCase()}.`,
      whenLabel: 'Dikirim',
      when: fmtDateTime(current.created_at),
    },
    {
      index: '02',
      meta: 'PENINJAUAN',
      title: 'Ditinjau manajemen',
      note:
        current.status === 'open'
          ? 'Belum ada operator yang meninjau laporan ini.'
          : 'Laporan masuk antrean tinjauan operator dan sudah dibuka.',
      whenLabel: 'Diperbarui',
      when: current.status === 'open' ? null : fmtDateTime(current.updated_at),
    },
    {
      index: '03',
      meta: 'KEPUTUSAN',
      title:
        current.status === 'resolved'
          ? 'Diselesaikan'
          : current.status === 'dismissed'
            ? 'Ditolak'
            : 'Menunggu keputusan',
      note: decided
        ? current.resolution ||
          (current.status === 'dismissed'
            ? 'Ditandai bukan masalah oleh manajemen.'
            : 'Tanpa catatan resolusi.')
        : 'Hasil tinjauan menentukan apakah laporan selesai atau ditolak.',
      whenLabel: 'Diselesaikan',
      when: decided ? fmtDateTime(current.resolved_at) : null,
    },
  ]

  const stateLabel = ['Selesai', 'Berjalan', 'Menunggu']

  return steps.map((step, index) => ({
    ...step,
    state: index < position ? 'done' : index === position ? 'current' : 'pending',
    stateLabel: stateLabel[index < position ? 0 : index === position ? 1 : 2],
  }))
})

async function load() {
  loading.value = true
  error.value = null
  try {
    issue.value = (await apiGet(`/issues/${id}`)).data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat detail laporan.'
  } finally {
    loading.value = false
  }
}

function startMotion() {
  motion.reveal('.detail__section', { stagger: 0.07 })
  motion.scrubProgress('.timeline__fill', '.timeline', { start: 'top 78%', end: 'bottom 62%' })
  motion.refresh()
}

onMounted(async () => {
  await load()
  if (issue.value) {
    startMotion()
  }
})

/* Pelayoutan berubah setiap aksi/toggle form — hitung ulang posisi scroll. */
watch([issue, resolveOpen, dismissOpen], async () => {
  await nextTick()
  motion.refresh()
})

async function review() {
  reviewError.value = null
  try {
    await apiPost(`/issues/${id}/review`)
    await load()
    toast.ok('Laporan ditandai sedang ditinjau.')
  } catch (e) {
    reviewError.value = e instanceof ApiError ? e.message : 'Gagal meninjau laporan.'
  }
}

async function resolve() {
  resolveSending.value = true
  resolveError.value = null
  try {
    await apiPost(`/issues/${id}/resolve`, { resolution: resolution.value })
    resolveOpen.value = false
    resolution.value = ''
    await load()
    toast.ok('Laporan diselesaikan.')
  } catch (e) {
    resolveError.value = e instanceof ApiError ? e.message : 'Gagal menyelesaikan laporan.'
  } finally {
    resolveSending.value = false
  }
}

async function dismiss() {
  dismissSending.value = true
  dismissError.value = null
  try {
    await apiPost(`/issues/${id}/dismiss`, { reason: dismissReason.value })
    dismissOpen.value = false
    dismissReason.value = ''
    await load()
    toast.ok('Laporan ditolak.')
  } catch (e) {
    dismissError.value = e instanceof ApiError ? e.message : 'Gagal menolak laporan.'
  } finally {
    dismissSending.value = false
  }
}

// --- Create ticket from issue ---
function createTicketFromIssue(assetId, issueId) {
  const data = {
    asset: assetId,
    issue: issueId,
  }
  sessionStorage.setItem('ticket-request-data', JSON.stringify(data))
  router.push({ name: 'ticket-create' })
}
</script>

<template>
  <section ref="page" class="detail">
    <RouterLink :to="{ name: 'issues' }" class="back link-sweep"
      >&larr; Kembali ke laporan</RouterLink
    >

    <LoadingState v-if="loading" label="Memuat detail laporan" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="issue">
      <PageHead label="LAPORAN MASALAH" :title="issue.asset?.name ?? 'Aset tidak tercatat'">
        Dilaporkan {{ fmtDateTime(issue.created_at) }}
        <template v-if="issue.reporter"> oleh {{ issue.reporter.name }}</template>
        <template v-if="issue.loan_id"> pada peminjaman #{{ issue.loan_id }}</template
        >.
        <template #actions>
          <StatusBadge :tone="ISSUE_TONES[issue.status] ?? 'neutral'">
            {{ issue.status_label }}
          </StatusBadge>
          <StatusBadge tone="neutral">{{ issue.type_label }}</StatusBadge>
          <StatusBadge tone="neutral">{{ issue.severity_label }}</StatusBadge>
        </template>
      </PageHead>

      <section class="panel detail__section" aria-labelledby="desc-title">
        <p class="index-label">DESKRIPSI</p>
        <h2 id="desc-title">Deskripsi</h2>
        <p class="desc">{{ issue.description }}</p>
        <div v-if="(issue.photos ?? []).length" class="photos">
          <a
            v-for="photo in issue.photos"
            :key="photo.id"
            :href="photo.url"
            target="_blank"
            rel="noopener"
          >
            <img :src="photo.url" alt="Foto laporan masalah" class="photos__img" />
          </a>
        </div>
      </section>

      <section class="panel detail__section" aria-labelledby="flow-title">
        <p class="index-label">ALUR STATUS</p>
        <h2 id="flow-title">Alur laporan</h2>

        <div class="timeline-wrap">
          <span class="timeline__rail" aria-hidden="true"
            ><span class="timeline__fill"></span
          ></span>
          <ol class="timeline">
            <li
              v-for="step in timeline"
              :key="step.index"
              class="timeline__step"
              :class="`timeline__step--${step.state}`"
            >
              <span class="timeline__node" aria-hidden="true"></span>
              <div class="timeline__body">
                <p class="timeline__meta">{{ step.meta }}</p>
                <h3 class="timeline__title">{{ step.title }}</h3>
                <p class="timeline__note text-2">{{ step.note }}</p>
                <p class="timeline__when">
                  <span class="num">{{ step.when ?? step.whenLabel }}</span>
                  <span class="timeline__state">{{ step.stateLabel }}</span>
                </p>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <section
        v-if="issue.resolution || issue.resolved_at"
        class="panel detail__section"
        aria-labelledby="res-title"
      >
        <p class="index-label">PENYELESAIAN</p>
        <h2 id="res-title">Penyelesaian</h2>
        <p v-if="issue.resolution" class="desc">{{ issue.resolution }}</p>
        <p v-if="issue.resolved_at" class="text-2">
          Diselesaikan {{ fmtDateTime(issue.resolved_at) }}.
        </p>
      </section>

      <section
        v-if="canReview || canResolve || canDismiss"
        class="panel detail__section"
        aria-labelledby="acts-title"
      >
        <p class="index-label">AKSI</p>
        <h2 id="acts-title">Aksi</h2>
        <div class="act-row">
          <button v-if="canReview" type="button" class="btn" @click="review">
            Tandai ditinjau
          </button>
          <button v-if="canResolve" type="button" class="btn" @click="resolveOpen = !resolveOpen">
            {{ resolveOpen ? 'Tutup penyelesaian' : 'Selesaikan' }}
          </button>
          <button v-if="canDismiss" type="button" class="btn" @click="dismissOpen = !dismissOpen">
            {{ dismissOpen ? 'Tutup penolakan' : 'Tolak laporan' }}
          </button>
          <button
            v-if="['open', 'reviewing'].includes(issue.status)"
            @click="createTicketFromIssue(issue.asset_id, issue.id)"
            class="btn btn--primary"
          >
            Buat tiket perawatan
          </button>
        </div>
        <p v-if="reviewError" class="form-error" role="alert">{{ reviewError }}</p>

        <form v-if="resolveOpen" class="mini-form" @submit.prevent="resolve">
          <div class="field">
            <label for="resolve-text">Resolusi</label>
            <textarea
              id="resolve-text"
              v-model="resolution"
              class="input"
              rows="2"
              maxlength="5000"
              required
            ></textarea>
          </div>
          <p v-if="resolveError" class="form-error" role="alert">{{ resolveError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="resolveSending">
              {{ resolveSending ? 'Menyelesaikan' : 'Selesaikan laporan' }}
            </button>
            <button type="button" class="btn" @click="resolveOpen = false">Batal</button>
          </div>
        </form>

        <form v-if="dismissOpen" class="mini-form" @submit.prevent="dismiss">
          <div class="field">
            <label for="dismiss-text">Alasan penolakan</label>
            <textarea
              id="dismiss-text"
              v-model="dismissReason"
              class="input"
              rows="2"
              maxlength="5000"
              required
            ></textarea>
          </div>
          <p v-if="dismissError" class="form-error" role="alert">{{ dismissError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="dismissSending">
              {{ dismissSending ? 'Menolak' : 'Tolak laporan' }}
            </button>
            <button type="button" class="btn" @click="dismissOpen = false">Batal</button>
          </div>
        </form>
      </section>
    </template>
  </section>
</template>

<style scoped>
.detail {
  display: grid;
  gap: 1.75rem;
}

.back {
  justify-self: start;
  font-weight: 600;
  text-decoration: none;
}

.panel {
  padding-top: 1.25rem;
  border-top: 2px solid var(--ln-border-strong);
}

.panel .index-label {
  margin-bottom: 0.5rem;
}

.panel h2 {
  margin: 0 0 0.75rem;
}

.desc {
  margin: 0;
  white-space: pre-wrap;
}

.photos {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 0.75rem;
}

.photos__img {
  width: 7rem;
  height: 7rem;
  object-fit: cover;
  border: 1px solid var(--ln-border);
}

/* Garis waktu bernomor dengan node dan rail progres. */
.timeline-wrap {
  position: relative;
  padding: 1rem 0 0 2.5rem;
  border-top: 1px solid var(--ln-border);
}

.timeline {
  display: grid;
  gap: 1.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.timeline__rail {
  position: absolute;
  top: 1rem;
  bottom: 1rem;
  left: 0.5rem;
  width: 1px;
  background: var(--ln-border);
}

.timeline__fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
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
  border: 1px solid var(--ln-border);
  background: var(--ln-bg);
}

.timeline__step--done .timeline__node {
  border-color: var(--ln-text);
  background: var(--ln-text);
}

.timeline__step--current .timeline__node {
  border-color: var(--ln-text);
  background: var(--ln-bg);
}

.timeline__body {
  display: grid;
  gap: 0.375rem;
}

.timeline__meta {
  margin: 0;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.timeline__title {
  margin: 0;
  font-size: 1.25rem;
}

.timeline__note {
  margin: 0;
  max-width: 56ch;
  font-size: 0.9375rem;
  line-height: 1.6;
}

.timeline__when {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin: 0.25rem 0 0;
}

.timeline__when .num {
  color: var(--ln-text-2);
  font-size: 0.75rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.timeline__state {
  padding: 0.125rem 0.5rem;
  border: 1px solid var(--ln-border-strong);
  color: var(--ln-text);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.act-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
}

.mini-form {
  margin-top: 1rem;
  max-width: 36rem;
  padding: 1rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
}

@media (max-width: 48rem) {
  .timeline-wrap {
    padding-left: 2rem;
  }

  .timeline__node {
    left: -2rem;
  }
}
</style>
