<script setup>
/*
 * Detail tiket perawatan untuk operator/admin. Alur kerja mengikuti mesin
 * status backend: tugaskan → mulai → (tunggu komponen | selesai) →
 * verifikasi, atau batalkan. Verifikasi tidak bisa dilakukan oleh teknisi
 * yang mengerjakan tiket tersebut (pemisahan tugas).
 * Logika aksi dan permission tidak berubah; yang baru hanya kepala halaman,
 * garis waktu riwayat bernomor, dan gerak.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { auth } from '../lib/auth'
import { CONDITION_OPTIONS, fmtDate, fmtDateTime, fmtPrice, TICKET_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const id = Number(route.params.id)

const ticket = ref(null)
const members = ref([])
const loading = ref(true)
const error = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const assignOpen = ref(false)
const assigneeId = ref('')
const assignSending = ref(false)
const assignError = ref(null)
const startError = ref(null)

const noteOpen = ref(null)
const note = ref('')
const noteSending = ref(false)
const noteError = ref(null)

const cancelOpen = ref(false)
const cancelReason = ref('')
const cancelSending = ref(false)
const cancelError = ref(null)

const verifyOpen = ref(false)
const verifyCondition = ref('good')
const verifyNotes = ref('')
const verifyPhotos = ref([])
const verifySending = ref(false)
const verifyError = ref(null)
const verifyFieldErrors = ref({})

const NOTES_REQUIRED = ['poor', 'unserviceable']

const status = computed(() => ticket.value?.status)
const isAssignee = computed(
  () => ticket.value?.assignee?.id != null && ticket.value.assignee.id === auth.user?.id,
)
const canAssign = computed(() => status.value === 'open')
const canStart = computed(() => status.value === 'assigned')
const canProgress = computed(() => status.value === 'in_progress')
const canVerify = computed(() => status.value === 'awaiting_verification' && !isAssignee.value)
const canCancel = computed(() =>
  ['open', 'assigned', 'in_progress', 'awaiting_parts'].includes(status.value),
)
const hasActions = computed(
  () =>
    canAssign.value || canStart.value || canProgress.value || canVerify.value || canCancel.value,
)

const activeMembers = computed(() => members.value.filter((m) => m.status === 'active' && m.user))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [ticketPayload, memberPayload] = await Promise.all([
      apiGet(`/maintenance-tickets/${id}`),
      apiGet('/memberships?limit=100'),
    ])
    ticket.value = ticketPayload.data
    members.value = memberPayload.data ?? []
    assigneeId.value = ticket.value.assignee?.id != null ? String(ticket.value.assignee.id) : ''
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat detail tiket.'
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
  if (ticket.value) {
    startMotion()
  }
})

/* Pelayoutan berubah setelah tiap aksi/toggle form — hitung ulang posisi scroll. */
watch([ticket, assignOpen, noteOpen, verifyOpen, cancelOpen], async () => {
  await nextTick()
  motion.refresh()
})

async function runAction(path, body, after) {
  try {
    await apiPost(path, body)
    after()
    await load()
  } catch (e) {
    return e instanceof ApiError ? e.message : 'Gagal memproses aksi.'
  }
  return null
}

async function assign() {
  assignSending.value = true
  assignError.value = null
  const message = await runAction(
    `/maintenance-tickets/${id}/assign`,
    { assigned_to: assigneeId.value ? Number(assigneeId.value) : null },
    () => {
      assignOpen.value = false
      toast.ok('Tiket ditugaskan.')
    },
  )
  assignError.value = message
  assignSending.value = false
}

async function start() {
  startError.value = null
  const message = await runAction(`/maintenance-tickets/${id}/start`, undefined, () => {
    toast.ok('Pengerjaan dimulai.')
  })
  if (message) {
    startError.value = message
  }
}

async function progress(action) {
  noteSending.value = true
  noteError.value = null
  const message = await runAction(
    `/maintenance-tickets/${id}/${action}`,
    { note: note.value.trim() ? note.value.trim() : null },
    () => {
      noteOpen.value = null
      note.value = ''
      toast.ok(
        action === 'await-parts'
          ? 'Tiket menunggu komponen.'
          : 'Pengerjaan selesai, menunggu verifikasi.',
      )
    },
  )
  noteError.value = message
  noteSending.value = false
}

async function verify() {
  verifySending.value = true
  verifyError.value = null
  verifyFieldErrors.value = {}

  const fd = new FormData()
  fd.append('condition', verifyCondition.value)
  fd.append('notes', verifyNotes.value.trim())
  verifyPhotos.value.forEach((file) => {
    fd.append('photos[]', file)
  })

  try {
    await apiPost(`/maintenance-tickets/${id}/verify`, fd)
    verifyOpen.value = false
    verifyNotes.value = ''
    verifyPhotos.value = []
    await load()
    toast.ok('Perbaikan terverifikasi, tiket selesai.')
  } catch (e) {
    if (e instanceof ApiError) {
      verifyError.value = e.message
      verifyFieldErrors.value = e.errors ?? {}
    } else {
      verifyError.value = 'Gagal memverifikasi tiket.'
    }
  } finally {
    verifySending.value = false
  }
}

async function cancel() {
  cancelSending.value = true
  cancelError.value = null
  const message = await runAction(
    `/maintenance-tickets/${id}/cancel`,
    { reason: cancelReason.value },
    () => {
      cancelOpen.value = false
      cancelReason.value = ''
      toast.ok('Tiket dibatalkan.')
    },
  )
  cancelError.value = message
  cancelSending.value = false
}

function addVerifyPhotos(event) {
  verifyPhotos.value.push(...Array.from(event.target.files ?? []))
}

function removeVerifyPhoto(index) {
  verifyPhotos.value.splice(index, 1)
}
</script>

<template>
  <section ref="page" class="detail">
    <RouterLink :to="{ name: 'maintenance' }" class="back link-sweep"
      >&larr; Kembali ke perawatan</RouterLink
    >

    <LoadingState v-if="loading" label="Memuat detail tiket" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="ticket">
      <PageHead label="TIKET PERAWATAN" :title="ticket.asset?.name ?? 'Aset tidak tercatat'">
        Dibuat {{ fmtDateTime(ticket.created_at) }}.
        <template v-if="ticket.assignee"> Teknisi: {{ ticket.assignee.name }}.</template>
        <template #actions>
          <StatusBadge :tone="TICKET_TONES[ticket.status] ?? 'neutral'">
            {{ ticket.status_label }}
          </StatusBadge>
          <StatusBadge tone="neutral">Prioritas {{ ticket.priority_label }}</StatusBadge>
          <StatusBadge v-if="ticket.is_blocking" tone="err">Menghambat peminjaman</StatusBadge>
        </template>
      </PageHead>

      <div class="facts-block detail__section">
        <p class="index-label">RINCIAN</p>
        <dl class="facts">
          <div v-if="ticket.issue">
            <dt>Laporan terkait</dt>
            <dd>
              <RouterLink :to="{ name: 'issue-detail', params: { id: ticket.issue.id } }">
                Laporan #{{ ticket.issue.id }} ({{ ticket.issue.status_label }})
              </RouterLink>
            </dd>
          </div>
          <div v-if="ticket.scheduled_start || ticket.scheduled_end">
            <dt>Jadwal</dt>
            <dd>
              {{ fmtDateTime(ticket.scheduled_start) }} sampai
              {{ fmtDateTime(ticket.scheduled_end) }}
            </dd>
          </div>
          <div v-if="ticket.estimated_cost != null || ticket.actual_cost != null">
            <dt>Biaya</dt>
            <dd>
              <template v-if="ticket.estimated_cost != null">
                Perkiraan {{ fmtPrice(ticket.estimated_cost) }}
              </template>
              <template v-if="ticket.actual_cost != null">
                , aktual {{ fmtPrice(ticket.actual_cost) }}
              </template>
            </dd>
          </div>
          <div v-if="ticket.description">
            <dt>Deskripsi</dt>
            <dd class="facts__wide">{{ ticket.description }}</dd>
          </div>
        </dl>
      </div>

      <section class="panel detail__section" aria-labelledby="logs-title">
        <p class="index-label">RIWAYAT</p>
        <h2 id="logs-title">Riwayat pengerjaan</h2>

        <div v-if="(ticket.logs ?? []).length" class="timeline-wrap">
          <span class="timeline__rail" aria-hidden="true"
            ><span class="timeline__fill"></span
          ></span>
          <ol class="timeline">
            <li v-for="log in ticket.logs" :key="log.id" class="timeline__step">
              <span class="timeline__node" aria-hidden="true"></span>
              <div class="timeline__body">
                <h3 class="timeline__title">{{ log.action_label }}</h3>
                <p v-if="log.actor || log.cost != null" class="timeline__note text-2">
                  <template v-if="log.actor">oleh {{ log.actor.name }}</template>
                  <template v-if="log.cost != null"> · {{ fmtPrice(log.cost) }}</template>
                </p>
                <p class="timeline__when">
                  <span class="num">{{ fmtDateTime(log.created_at) }}</span>
                </p>
                <p v-if="log.note" class="timeline__extra">{{ log.note }}</p>
              </div>
            </li>
          </ol>
        </div>
        <p v-else class="text-2">Belum ada riwayat.</p>
      </section>

      <section v-if="hasActions" class="panel detail__section" aria-labelledby="acts-title">
        <p class="index-label">AKSI</p>
        <h2 id="acts-title">Aksi</h2>
        <div class="act-row">
          <button
            v-if="canAssign"
            type="button"
            class="btn btn--primary"
            @click="assignOpen = !assignOpen"
          >
            {{ assignOpen ? 'Tutup penugasan' : 'Tugaskan teknisi' }}
          </button>
          <button v-if="canStart" type="button" class="btn btn--primary" @click="start">
            Mulai kerjakan
          </button>
          <button
            v-if="canProgress"
            type="button"
            class="btn"
            @click="noteOpen = noteOpen === 'await-parts' ? null : 'await-parts'"
          >
            Tunggu komponen
          </button>
          <button
            v-if="canProgress"
            type="button"
            class="btn btn--primary"
            @click="noteOpen = noteOpen === 'complete' ? null : 'complete'"
          >
            Selesai dikerjakan
          </button>
          <button
            v-if="canVerify"
            type="button"
            class="btn btn--primary"
            @click="verifyOpen = !verifyOpen"
          >
            {{ verifyOpen ? 'Tutup verifikasi' : 'Verifikasi hasil' }}
          </button>
          <button v-if="canCancel" type="button" class="btn" @click="cancelOpen = !cancelOpen">
            {{ cancelOpen ? 'Tutup pembatalan' : 'Batalkan tiket' }}
          </button>
        </div>
        <p v-if="startError" class="form-error" role="alert">{{ startError }}</p>
        <p v-if="status === 'awaiting_verification' && isAssignee" class="text-2">
          Kamu tercatat sebagai teknisi tiket ini, sehingga verifikasi harus dilakukan operator
          lain.
        </p>

        <form v-if="assignOpen" class="mini-form" @submit.prevent="assign">
          <div class="field">
            <label for="assign-to">Teknisi (kosongkan untuk melepas)</label>
            <select id="assign-to" v-model="assigneeId" class="input">
              <option value="">Belum ditugaskan</option>
              <option v-for="m in activeMembers" :key="m.id" :value="String(m.user.id)">
                {{ m.user.name }} ({{ m.role_label }})
              </option>
            </select>
          </div>
          <p v-if="assignError" class="form-error" role="alert">{{ assignError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="assignSending">
              {{ assignSending ? 'Menugaskan' : 'Tugaskan' }}
            </button>
            <button type="button" class="btn" @click="assignOpen = false">Batal</button>
          </div>
        </form>

        <form v-if="noteOpen" class="mini-form" @submit.prevent="progress(noteOpen)">
          <div class="field">
            <label for="progress-note">Catatan (opsional)</label>
            <textarea
              id="progress-note"
              v-model="note"
              class="input"
              rows="2"
              maxlength="5000"
            ></textarea>
          </div>
          <p v-if="noteError" class="form-error" role="alert">{{ noteError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="noteSending">
              {{
                noteSending
                  ? 'Mengirim'
                  : noteOpen === 'await-parts'
                    ? 'Tandai menunggu komponen'
                    : 'Tandai selesai'
              }}
            </button>
            <button type="button" class="btn" @click="noteOpen = null">Batal</button>
          </div>
        </form>

        <form v-if="verifyOpen" class="mini-form" @submit.prevent="verify">
          <div class="field">
            <label for="verify-condition">Kondisi aset setelah perbaikan</label>
            <select id="verify-condition" v-model="verifyCondition" class="input" required>
              <option v-for="cond in CONDITION_OPTIONS" :key="cond.value" :value="cond.value">
                {{ cond.label }}
              </option>
            </select>
            <span v-if="verifyFieldErrors.condition" class="field-error">{{
              verifyFieldErrors.condition[0]
            }}</span>
          </div>
          <div class="field">
            <label for="verify-notes">
              Catatan{{
                NOTES_REQUIRED.includes(verifyCondition)
                  ? ' (wajib untuk kondisi buruk)'
                  : ' (opsional)'
              }}
            </label>
            <textarea
              id="verify-notes"
              v-model="verifyNotes"
              class="input"
              rows="2"
              maxlength="5000"
              :required="NOTES_REQUIRED.includes(verifyCondition)"
            ></textarea>
            <span v-if="verifyFieldErrors.notes" class="field-error">{{
              verifyFieldErrors.notes[0]
            }}</span>
          </div>
          <div class="field">
            <label for="verify-photos">Foto (opsional)</label>
            <input
              id="verify-photos"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              multiple
              class="input"
              @change="addVerifyPhotos"
            />
            <ul v-if="verifyPhotos.length" class="photo-list">
              <li v-for="(photo, index) in verifyPhotos" :key="index" class="photo-item">
                <span class="text-2">{{ photo.name }}</span>
                <button type="button" class="btn btn--small" @click="removeVerifyPhoto(index)">
                  Hapus
                </button>
              </li>
            </ul>
          </div>
          <p v-if="verifyError" class="form-error" role="alert">{{ verifyError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="verifySending">
              {{ verifySending ? 'Memverifikasi' : 'Verifikasi dan selesaikan' }}
            </button>
            <button type="button" class="btn" @click="verifyOpen = false">Batal</button>
          </div>
        </form>

        <form v-if="cancelOpen" class="mini-form" @submit.prevent="cancel">
          <div class="field">
            <label for="cancel-reason">Alasan pembatalan (wajib)</label>
            <textarea
              id="cancel-reason"
              v-model="cancelReason"
              class="input"
              rows="2"
              maxlength="5000"
              required
            ></textarea>
          </div>
          <p v-if="cancelError" class="form-error" role="alert">{{ cancelError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="cancelSending">
              {{ cancelSending ? 'Membatalkan' : 'Batalkan tiket' }}
            </button>
            <button type="button" class="btn" @click="cancelOpen = false">Batal</button>
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

.facts-block .index-label {
  margin-bottom: 0.75rem;
}

/* Rincian: daftar definisi bergaris, bukan kartu. */
.facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 2rem;
  margin: 0;
  padding: 0;
  border-top: 1px solid var(--ln-border);
}

.facts > div {
  display: grid;
  gap: 0.25rem;
  align-content: start;
  min-height: 44px;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.facts dt {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.facts dd {
  margin: 0;
  font-weight: 600;
}

.facts__wide {
  grid-column: 1 / -1;
  font-weight: 400 !important;
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

/* Garis waktu riwayat bernomor dengan node dan rail progres. */
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
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-text);
}

.timeline__body {
  display: grid;
  gap: 0.375rem;
}

.timeline__title {
  margin: 0;
  font-size: 1.25rem;
}

.timeline__note {
  margin: 0;
  font-size: 0.9375rem;
}

.timeline__when {
  margin: 0;
}

.timeline__when .num {
  color: var(--ln-text-2);
  font-size: 0.75rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.timeline__extra {
  margin: 0.25rem 0 0;
  max-width: 56ch;
  padding-left: 0.75rem;
  border-left: 1px solid var(--ln-border-strong);
  white-space: pre-wrap;
  font-size: 0.875rem;
  line-height: 1.6;
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

.photo-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.25rem;
}

.photo-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
}

@media (max-width: 48rem) {
  .facts {
    grid-template-columns: 1fr;
  }

  .timeline-wrap {
    padding-left: 2rem;
  }

  .timeline__node {
    left: -2rem;
  }
}
</style>
