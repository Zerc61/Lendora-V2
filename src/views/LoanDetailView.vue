<script setup>
/*
 * Detail pinjaman untuk semua role. Borrower melihat pinjaman miliknya dan
 * dapat membatalkan; operator dan admin dapat menyetujui, menolak, atau
 * membatalkan. Riwayat kondisi aset hanya tampil untuk operator dan admin.
 *
 * Presentasi: alur status sebagai garis waktu bernomor (pengajuan →
 * persetujuan → serah terima → pengembalian) dengan rail progres yang terisi
 * mengikuti gulir. Logika, API, permission, dan handler tidak berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { auth } from '../lib/auth'
import { fmtDate, fmtDateTime, LOAN_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'

const route = useRoute()
const id = Number(route.params.id)

const loan = ref(null)
const loading = ref(true)
const error = ref(null)

const rejectOpen = ref(false)
const rejectReason = ref('')
const rejectSending = ref(false)
const rejectError = ref(null)

const approveOpen = ref(false)
const approveSending = ref(false)
const approveError = ref(null)

const cancelOpen = ref(false)
const cancelReason = ref('')
const cancelSending = ref(false)
const cancelError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const isManagement = computed(() => auth.role === 'operator' || auth.role === 'admin')
const isAdmin = computed(() => auth.role === 'admin')
const isOwner = computed(() => loan.value?.borrower?.id === auth.user?.id)

const canApprove = computed(() => isManagement.value && loan.value?.status === 'requested')
const canReject = canApprove
const canCancel = computed(() => {
  const status = loan.value?.status
  if (!status || !['requested', 'approved'].includes(status)) {
    return false
  }
  if (isAdmin.value) {
    return true
  }
  return isOwner.value
})
const canCheckout = computed(
  () => isManagement.value && loan.value?.status === 'approved' && loan.value?.is_awaiting_pickup,
)
const canCheckin = computed(() => isManagement.value && loan.value?.status === 'checked_out')

async function load() {
  loading.value = true
  error.value = null
  try {
    loan.value = (await apiGet(`/loans/${id}`)).data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat detail peminjaman.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.block', { stagger: 0.08 })
  motion.scrubProgress('.flow__rail-fill', '.flow', { start: 'top 78%', end: 'bottom 62%' })
  motion.refresh()
})

async function approve() {
  approveSending.value = true
  approveError.value = null
  try {
    await apiPost(`/loans/${id}/approve`)
    approveOpen.value = false
    await load()
    toast.ok('Pengajuan disetujui.')
  } catch (e) {
    approveError.value = e instanceof ApiError ? e.message : 'Gagal menyetujui peminjaman.'
  } finally {
    approveSending.value = false
  }
}

async function reject() {
  rejectSending.value = true
  rejectError.value = null
  try {
    await apiPost(`/loans/${id}/reject`, { reason: rejectReason.value })
    rejectOpen.value = false
    rejectReason.value = ''
    await load()
    toast.ok('Pengajuan ditolak.')
  } catch (e) {
    rejectError.value = e instanceof ApiError ? e.message : 'Gagal menolak peminjaman.'
  } finally {
    rejectSending.value = false
  }
}

async function cancel() {
  cancelSending.value = true
  cancelError.value = null
  try {
    await apiPost(`/loans/${id}/cancel`, { reason: cancelReason.value || null })
    cancelOpen.value = false
    cancelReason.value = ''
    await load()
    toast.ok('Peminjaman dibatalkan.')
  } catch (e) {
    cancelError.value = e instanceof ApiError ? e.message : 'Gagal membatalkan peminjaman.'
  } finally {
    cancelSending.value = false
  }
}

const TIMELINE = computed(() => {
  if (!loan.value) {
    return []
  }
  const entries = [
    { at: loan.value.created_at, label: 'Pengajuan dibuat' },
    { at: loan.value.approved_at, label: 'Disetujui' },
    { at: loan.value.checked_out_at, label: 'Aset diambil' },
    { at: loan.value.returned_at, label: 'Aset dikembalikan' },
  ]
  return entries.filter((entry) => entry.at)
})

/* Empat langkah alur pinjaman, diturunkan dari riwayat waktu yang sama. */
const FLOW_STEPS = computed(() => {
  const at = (label) => TIMELINE.value.find((entry) => entry.label === label)?.at ?? null
  const status = loan.value?.status
  const blocked =
    status === 'rejected'
      ? 'Ditolak'
      : status === 'cancelled'
        ? 'Dibatalkan'
        : status === 'expired'
          ? 'Kedaluwarsa'
          : null
  const steps = [
    {
      key: 'requested',
      title: 'Pengajuan dibuat',
      at: at('Pengajuan dibuat'),
      pending: 'Menunggu',
    },
    {
      key: 'approved',
      title: 'Disetujui operator',
      at: at('Disetujui'),
      pending: 'Menunggu persetujuan',
    },
    {
      key: 'checked_out',
      title: 'Aset diserahkan',
      at: at('Aset diambil'),
      pending: 'Menunggu pengambilan',
    },
    {
      key: 'returned',
      title: 'Aset dikembalikan',
      at: at('Aset dikembalikan'),
      pending: 'Menunggu pengembalian',
    },
  ]
  const firstPending = steps.findIndex((step) => !step.at)
  const activeIndex = firstPending === -1 ? steps.length - 1 : firstPending
  return steps.map((step, index) => ({
    ...step,
    done: Boolean(step.at),
    active: index === activeIndex,
    pending: index > firstPending && firstPending !== -1 ? (blocked ?? step.pending) : step.pending,
  }))
})
</script>

<template>
  <section ref="page" class="detail">
    <RouterLink :to="{ name: 'loans' }" class="back link-sweep"
      >&larr; Kembali ke peminjaman</RouterLink
    >

    <LoadingState v-if="loading" label="Memuat detail peminjaman" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="loan">
      <PageHead
        label="PEMINJAMAN"
        :title="isOwner ? 'Pinjaman saya' : `Pinjaman ${loan.borrower?.name ?? ''}`"
        :lede="
          isOwner
            ? `Oleh kamu, ${fmtDateTime(loan.created_at)}.`
            : `Diajukan ${fmtDateTime(loan.created_at)}.`
        "
      >
        <template #actions>
          <StatusBadge :tone="LOAN_TONES[loan.status] ?? 'neutral'">
            {{ loan.status_label }}
          </StatusBadge>
          <span v-if="loan.is_overdue" class="tag tag--warn">Terlambat</span>
          <span v-if="loan.is_awaiting_pickup" class="tag tag--ok">Siap diambil</span>
          <span v-if="loan.is_expired" class="tag tag--warn">Pengambilan kedaluwarsa</span>
        </template>
      </PageHead>

      <dl class="facts">
        <div>
          <dt>Periode</dt>
          <dd>{{ fmtDateTime(loan.starts_at) }} sampai {{ fmtDateTime(loan.ends_at) }}</dd>
        </div>
        <div>
          <dt>Jatuh tempo</dt>
          <dd>
            {{ fmtDateTime(loan.due_at) || 'Belum ditetapkan' }}
            <template v-if="loan.is_overdue">, {{ loan.days_until_due }} hari lewat</template>
          </dd>
        </div>
        <div v-if="loan.is_awaiting_pickup">
          <dt>Ambil sebelum</dt>
          <dd>{{ fmtDateTime(loan.pickup_deadline) }}</dd>
        </div>
        <div v-if="loan.purpose">
          <dt>Keperluan</dt>
          <dd class="facts__wide">{{ loan.purpose }}</dd>
        </div>
      </dl>

      <section class="block" aria-labelledby="flow-title">
        <p class="index-label">ALUR</p>
        <h2 id="flow-title" class="block__title">Alur peminjaman</h2>

        <div class="flow">
          <span class="flow__rail" aria-hidden="true"><span class="flow__rail-fill"></span></span>
          <ol class="flow__steps">
            <li
              v-for="step in FLOW_STEPS"
              :key="step.key"
              class="flow__step"
              :class="{
                'flow__step--done': step.done,
                'flow__step--pending': !step.done,
                'flow__step--active': step.active,
              }"
            >
              <span class="flow__node" aria-hidden="true"></span>
              <div class="flow__body">
                <h3 class="flow__title">{{ step.title }}</h3>
                <p class="flow__meta">
                  <template v-if="step.done">{{ fmtDateTime(step.at) }} · Selesai</template>
                  <template v-else>{{ step.pending }} · Menunggu</template>
                </p>
              </div>
            </li>
          </ol>
        </div>

        <p v-if="loan.rejection_reason" class="reason">
          <strong>Alasan penolakan:</strong> {{ loan.rejection_reason }}
        </p>
        <p v-if="loan.cancellation_reason" class="reason">
          <strong>Alasan pembatalan:</strong> {{ loan.cancellation_reason }}
        </p>
      </section>

      <section class="block" aria-labelledby="items-title">
        <p class="index-label">ASET</p>
        <h2 id="items-title" class="block__title">Aset yang dipinjam</h2>
        <ul class="items">
          <li v-for="item in loan.items ?? []" :key="item.id" class="item">
            <RouterLink
              :to="{ name: 'asset-detail', params: { id: item.asset.id } }"
              class="item__link"
            >
              <img
                v-if="item.asset.photos?.[0]"
                class="item__thumb"
                :src="item.asset.photos[0].url"
                alt=""
              />
              <span v-else class="item__thumb item__thumb--empty" aria-hidden="true">Lendora</span>
              <span class="item__info">
                <strong>{{ item.asset.name }}</strong>
                <span class="text-2 num">Kode {{ item.asset.code }}</span>
              </span>
              <span class="item__go num" aria-hidden="true">→</span>
            </RouterLink>
          </li>
        </ul>
      </section>

      <section
        v-if="isManagement && (loan.condition_records ?? []).length"
        class="block"
        aria-labelledby="cond-title"
      >
        <p class="index-label">KONDISI</p>
        <h2 id="cond-title" class="block__title">Riwayat kondisi aset</h2>
        <ul class="conds">
          <li v-for="record in loan.condition_records" :key="record.id" class="cond">
            <div class="cond__top">
              <StatusBadge tone="neutral">{{ record.condition_label }}</StatusBadge>
              <span class="text-2">
                {{ record.source_label }}, {{ fmtDate(record.inspected_at) }}
                <template v-if="record.inspector"> oleh {{ record.inspector.name }}</template>
              </span>
            </div>
            <p v-if="record.notes || record.checklist_result" class="cond__notes">
              {{ record.notes }}
              <template v-if="record.checklist_result">
                {{ JSON.stringify(record.checklist_result) }}
              </template>
            </p>
            <p v-if="(record.photos ?? []).length" class="text-2">
              {{ record.photos.length }} foto terlampir pada catatan ini.
            </p>
          </li>
        </ul>
      </section>

      <section
        v-if="canApprove || canReject || canCancel || canCheckout || canCheckin"
        class="block"
        aria-labelledby="acts-title"
      >
        <p class="index-label">AKSI</p>
        <h2 id="acts-title" class="block__title">Tindakan</h2>
        <div class="act-row">
          <button
            v-if="canApprove"
            type="button"
            class="btn btn--primary"
            @click="approveOpen = true"
          >
            Setujui pengajuan
          </button>
          <button v-if="canReject" type="button" class="btn" @click="rejectOpen = !rejectOpen">
            {{ rejectOpen ? 'Tutup penolakan' : 'Tolak pengajuan' }}
          </button>
          <button v-if="canCancel" type="button" class="btn" @click="cancelOpen = !cancelOpen">
            {{ cancelOpen ? 'Tutup pembatalan' : 'Batalkan peminjaman' }}
          </button>
          <RouterLink
            v-if="canCheckout"
            :to="{ name: 'loan-checkout', params: { id } }"
            class="btn btn--primary"
          >
            Serah Terima
          </RouterLink>
          <RouterLink
            v-if="canCheckin"
            :to="{ name: 'loan-checkin', params: { id } }"
            class="btn btn--primary"
          >
            Terima Kembali
          </RouterLink>
        </div>

        <form v-if="rejectOpen" class="mini-form panel--alt" @submit.prevent="reject">
          <div class="field">
            <label for="reject-reason">Alasan penolakan</label>
            <textarea
              id="reject-reason"
              v-model="rejectReason"
              class="input"
              rows="2"
              minlength="3"
              required
            ></textarea>
          </div>
          <p v-if="rejectError" class="form-error" role="alert">{{ rejectError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="rejectSending">
              {{ rejectSending ? 'Menolak' : 'Tolak pengajuan' }}
            </button>
            <button type="button" class="btn" @click="rejectOpen = false">Batal</button>
          </div>
        </form>

        <form v-if="cancelOpen" class="mini-form panel--alt" @submit.prevent="cancel">
          <div class="field">
            <label for="cancel-reason">
              Alasan pembatalan{{ isAdmin && !isOwner ? ' (wajib)' : ' (opsional)' }}
            </label>
            <textarea
              id="cancel-reason"
              v-model="cancelReason"
              class="input"
              rows="2"
              :required="isAdmin && !isOwner"
            ></textarea>
          </div>
          <p v-if="cancelError" class="form-error" role="alert">{{ cancelError }}</p>
          <div class="act-row">
            <button type="submit" class="btn btn--primary" :disabled="cancelSending">
              {{ cancelSending ? 'Membatalkan' : 'Batalkan peminjaman' }}
            </button>
            <button type="button" class="btn" @click="cancelOpen = false">Batal</button>
          </div>
        </form>
      </section>
    </template>

    <ConfirmDialog
      v-if="approveOpen && loan"
      title="Setujui pengajuan ini?"
      :message="`Peminjaman oleh ${loan.borrower?.name ?? 'borrower'} disetujui dan masuk antrean serah terima.`"
      confirm-label="Setujui"
      :busy="approveSending"
      :error="approveError"
      @confirm="approve"
      @cancel="approveOpen = false"
    />
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

.tag {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 0.125rem 0.5rem;
}

.tag--ok {
  color: var(--ln-ok);
  border: 1px solid var(--ln-ok);
}

.tag--warn {
  color: var(--ln-warn);
  border: 1px solid var(--ln-warn);
}

/* Fakta: daftar bergaris, label mono huruf besar. */
.facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 2.5rem;
  margin: 0;
  border-top: 1px solid var(--ln-border);
}

.facts > div {
  padding: 0.875rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.facts dt {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.facts dd {
  margin: 0.25rem 0 0;
  font-weight: 600;
}

.facts__wide {
  grid-column: 1 / -1;
  font-weight: 400 !important;
}

/* Blok seksi: rule tebal di atas, judul display kecil. */
.block {
  display: grid;
  gap: 1rem;
  padding-top: 1.5rem;
  border-top: 2px solid var(--ln-border-strong);
}

.block__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

/* Garis waktu alur: nomor mono, node, dan rail progres. */
.flow {
  position: relative;
  padding-top: 0.5rem;
}

.flow__rail {
  position: absolute;
  top: 1.25rem;
  bottom: 1rem;
  left: 8px;
  width: 1px;
  background: var(--ln-border);
}

.flow__rail-fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
}

.flow__steps {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 2rem;
}

.flow__step {
  position: relative;
  display: grid;
  grid-template-columns: 1rem minmax(0, 1fr);
  align-items: start;
  gap: 0.875rem;
}

.flow__node {
  position: relative;
  z-index: 1;
  justify-self: center;
  width: 11px;
  height: 11px;
  margin-top: 0.4rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-bg);
}

.flow__step--done .flow__node {
  background: var(--ln-text);
}

.flow__step--active .flow__node {
  width: 17px;
  height: 17px;
  margin-top: 0.25rem;
  border-color: var(--ln-text);
  background: var(--ln-text);
  outline: 1px solid var(--ln-text);
  outline-offset: 3px;
}

.flow__body {
  display: grid;
}

.flow__title {
  margin: 0;
  font-size: 1.0625rem;
  letter-spacing: -0.02em;
}

.flow__step--active .flow__title {
  font-size: var(--ln-title);
}

.flow__step--pending .flow__title {
  color: var(--ln-text-2);
}

.flow__meta {
  margin: 0.25rem 0 0;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: 0.6875rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.reason {
  margin: 0;
  padding: 0.875rem 1rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
  font-size: 0.9375rem;
}

/* Daftar aset: baris bergaris, bukan kartu. */
.items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  border-top: 1px solid var(--ln-border);
}

.item__link {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  padding: 0.75rem 0.75rem;
  border-bottom: 1px solid var(--ln-border);
  color: var(--ln-text);
  text-decoration: none;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

.item__link:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.item__link:hover .text-2,
.item__link:hover .item__go {
  color: var(--ln-bg);
}

.item__thumb {
  width: 3rem;
  height: 3rem;
  object-fit: cover;
  border: 1px solid var(--ln-border);
  flex-shrink: 0;
}

.item__thumb--empty {
  display: grid;
  place-items: center;
  background: var(--ln-surface-alt);
  color: var(--ln-text-2);
  font-family: var(--ln-font-display);
  font-size: 0.6875rem;
  font-weight: 700;
}

.item__info {
  display: grid;
  gap: 0.125rem;
  flex: 1;
}

.item__go {
  color: var(--ln-n600);
  font-size: 0.875rem;
  transition: color var(--ln-motion-fast) var(--ln-ease);
}

/* Riwayat kondisi: baris bergaris. */
.conds {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  border-top: 1px solid var(--ln-border);
}

.cond {
  padding: 1rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.cond__top {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}

.cond__notes {
  margin: 0.5rem 0 0;
}

.act-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.mini-form {
  margin-top: 0.5rem;
  max-width: 36rem;
  padding: 1rem;
  border: 1px solid var(--ln-border);
}

@media (max-width: 36rem) {
  .facts {
    grid-template-columns: 1fr;
  }

  .flow__step {
    grid-template-columns: 1rem minmax(0, 1fr);
    gap: 0.625rem;
  }
}
</style>
