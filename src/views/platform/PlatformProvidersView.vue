<script setup>
/*
 * Verifikasi penyedia (keputusan 9). Hanya organisasi aktif dan terverifikasi
 * yang tampil di katalog publik, jadi keputusan di sini terasa langsung di
 * halaman Jelajah.
 *
 * Daftar ini lintas organisasi (endpoint /platform/*), di luar tenant.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch, ApiError } from '../../lib/http'
import { toast } from '../../lib/toast'
import { readQuery, writeQuery } from '../../lib/filterQuery'
import { usePageMotion } from '../../lib/motion'
import PageHead from '../../components/ui/PageHead.vue'
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import LoadingState from '../../components/LoadingState.vue'
import EmptyState from '../../components/EmptyState.vue'
import ErrorState from '../../components/ErrorState.vue'
import Pagination from '../../components/Pagination.vue'

const VERIFICATION_OPTIONS = [
  { value: 'pending', label: 'Menunggu verifikasi' },
  { value: 'verified', label: 'Terverifikasi' },
  { value: 'rejected', label: 'Ditolak' },
]

const VERIFICATION_TONES = {
  pending: 'warn',
  verified: 'ok',
  rejected: 'err',
}

const providers = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const filterQuery = ref('')
const filterStatus = ref('')

const busyId = ref(null)
const rejectTarget = ref(null)
const rejectError = ref(null)

const route = useRoute()
const router = useRouter()

const page = ref(null)
const motion = usePageMotion(page)

const hasFilters = computed(() => filterQuery.value !== '' || filterStatus.value !== '')

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams()

  if (filterStatus.value) {
    params.set('verification_status', filterStatus.value)
  }

  if (filterQuery.value) {
    params.set('q', filterQuery.value)
  }

  params.set('page', String(pageNumber))

  try {
    const payload = await apiGet(`/platform/providers?${params.toString()}`)
    providers.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar penyedia.'
  } finally {
    loading.value = false
  }
}

async function revealRows() {
  await nextTick()
  motion.reveal('.row', { y: 10, stagger: 0.03 })
  motion.refresh()
}

async function goToPage(pageNumber) {
  await load(pageNumber)
  await revealRows()
}

async function applyFilters() {
  writeQuery(router, route, { q: filterQuery.value, verification_status: filterStatus.value })
  await load(1)
  await revealRows()
}

async function resetFilters() {
  filterQuery.value = ''
  filterStatus.value = ''
  await applyFilters()
}

async function setVerification(target, status) {
  busyId.value = target.id

  try {
    await apiPatch(`/platform/providers/${target.id}/verification`, {
      verification_status: status,
    })
    toast.ok(
      status === 'verified'
        ? `${target.name} terverifikasi dan tampil di katalog publik.`
        : `${target.name} ditandai ditolak.`,
    )
    rejectTarget.value = null
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    const message = e instanceof ApiError ? e.message : 'Status verifikasi gagal diubah.'

    if (rejectTarget.value) {
      rejectError.value = message
    } else {
      toast.err(message)
    }
  } finally {
    busyId.value = null
  }
}

async function askReject(target) {
  rejectError.value = null
  rejectTarget.value = target
}

onMounted(async () => {
  const initial = readQuery(route, ['q', 'verification_status'])
  filterQuery.value = initial.q ?? ''
  filterStatus.value = initial.verification_status ?? ''

  await load(1)
  await revealRows()
})
</script>

<template>
  <section ref="page" class="platform-view">
    <PageHead
      label="PENYEDIA"
      title="Verifikasi penyedia"
      lede="Penyedia hanya tampil di halaman Jelajah bila organisasinya aktif dan terverifikasi di sini. Tolak atau cabut verifikasi untuk menyembunyikannya kembali."
    />

    <form class="filters" @submit.prevent="applyFilters">
      <div class="field">
        <label for="pp-q" class="filters__label">Cari</label>
        <input
          id="pp-q"
          v-model="filterQuery"
          class="input"
          maxlength="100"
          placeholder="Nama atau slug"
        />
      </div>
      <div class="field">
        <label for="pp-status" class="filters__label">Verifikasi</label>
        <select id="pp-status" v-model="filterStatus" class="input">
          <option value="">Semua status</option>
          <option v-for="opt in VERIFICATION_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="filters__actions">
        <button type="submit" class="btn btn--primary">Terapkan</button>
        <button type="button" class="btn" @click="resetFilters">Reset</button>
      </div>
    </form>

    <LoadingState v-if="loading" label="Memuat daftar penyedia" />

    <ErrorState
      v-else-if="error"
      title="Daftar penyedia gagal dimuat"
      :message="error"
      @retry="load(1)"
    />

    <template v-else-if="providers.length">
      <ul class="roster" aria-label="Daftar penyedia">
        <li v-for="item in providers" :key="item.id" class="row">
          <div class="row__id">
            <p class="row__name">{{ item.name }}</p>
            <p class="row__meta num">{{ item.slug }}</p>
            <p class="row__meta">
              {{ item.city ?? 'Kota belum diisi' }}
              <template v-if="item.contact_email"> · {{ item.contact_email }}</template>
            </p>
          </div>

          <div class="row__state">
            <StatusBadge :tone="VERIFICATION_TONES[item.verification_status] ?? 'neutral'">
              {{ item.verification_status_label ?? item.verification_status }}
            </StatusBadge>
            <p class="row__note num">
              {{ item.is_publicly_visible ? 'TAMPIL DI KATALOG PUBLIK' : 'BELUM TAMPIL PUBLIK' }}
            </p>
          </div>

          <div class="row__action">
            <button
              v-if="item.verification_status !== 'verified'"
              type="button"
              class="btn btn--primary"
              :disabled="busyId === item.id"
              @click="setVerification(item, 'verified')"
            >
              {{ busyId === item.id ? 'Memproses…' : 'Verifikasi' }}
            </button>
            <button
              v-if="item.verification_status !== 'rejected'"
              type="button"
              class="btn btn--ghost"
              :disabled="busyId === item.id"
              @click="askReject(item)"
            >
              {{ item.verification_status === 'verified' ? 'Cabut verifikasi' : 'Tolak' }}
            </button>
          </div>
        </li>
      </ul>

      <Pagination
        v-if="meta"
        :current="meta.current_page"
        :last="meta.last_page"
        :total="meta.total"
        :from="meta.from"
        :to="meta.to"
        @page="goToPage"
      />
    </template>

    <EmptyState
      v-else-if="hasFilters"
      title="Tidak ada penyedia yang cocok"
      hint="Ubah kata kunci atau status verifikasi, lalu terapkan lagi."
    />

    <EmptyState
      v-else
      title="Belum ada penyedia"
      hint="Organisasi yang mendaftar sebagai penyedia akan muncul di sini untuk diverifikasi."
    />

    <ConfirmDialog
      v-if="rejectTarget"
      title="Tolak verifikasi penyedia ini?"
      :message="`${rejectTarget.name} tidak akan tampil lagi di katalog publik sampai diverifikasi ulang.`"
      confirm-label="Tolak"
      :busy="busyId === rejectTarget.id"
      :error="rejectError"
      @confirm="setVerification(rejectTarget, 'rejected')"
      @cancel="rejectTarget = null"
    />
  </section>
</template>

<style scoped>
.platform-view {
  display: grid;
  gap: 1.5rem;
}

/* Baris filter: label mono huruf besar, garis rule atas-bawah. */
.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem 1rem;
  padding: 1.25rem 0;
  border-block: 1px solid var(--ln-border);
}

.filters .field {
  margin: 0;
  min-width: 11rem;
}

.filters__label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.filters__actions {
  display: flex;
  gap: 0.5rem;
}

.roster {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 1rem;
  padding-block: 1rem;
  border-bottom: 1px solid var(--ln-border);
}

.row__id {
  display: grid;
  gap: 0.125rem;
  min-width: 0;
}

.row__name {
  margin: 0;
  font-weight: 600;
}

.row__meta {
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  letter-spacing: 0.04em;
}

.row__state {
  display: grid;
  gap: 0.375rem;
  justify-items: start;
}

.row__note {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.6875rem;
  letter-spacing: 0.1em;
}

.row__action {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

@media (max-width: 48rem) {
  .row {
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
  }
}
</style>
