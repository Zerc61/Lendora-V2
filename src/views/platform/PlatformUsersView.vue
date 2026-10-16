<script setup>
/*
 * Aktivasi akun publik (keputusan 1). Akun yang mendaftar sendiri tanpa
 * organisasi berstatus `pending` dan baru bisa masuk setelah diaktifkan di
 * sini; menonaktifkan akun sekaligus memutus sesi yang sedang berjalan.
 *
 * Daftar ini lintas organisasi (endpoint /platform/*), di luar tenant.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch, ApiError } from '../../lib/http'
import { toast } from '../../lib/toast'
import { readQuery, writeQuery } from '../../lib/filterQuery'
import { fmtDate, fmtPrice } from '../../lib/format'
import { usePageMotion } from '../../lib/motion'
import PageHead from '../../components/ui/PageHead.vue'
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import LoadingState from '../../components/LoadingState.vue'
import EmptyState from '../../components/EmptyState.vue'
import ErrorState from '../../components/ErrorState.vue'
import Pagination from '../../components/Pagination.vue'

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Menunggu aktivasi' },
  { value: 'active', label: 'Aktif' },
  { value: 'inactive', label: 'Nonaktif' },
]

const STATUS_TONES = {
  pending: 'warn',
  active: 'ok',
  inactive: 'neutral',
}

const users = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const filterQuery = ref('')
const filterStatus = ref('')

const busyId = ref(null)
const deactivateTarget = ref(null)
const deactivateError = ref(null)

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
    params.set('status', filterStatus.value)
  }

  if (filterQuery.value) {
    params.set('q', filterQuery.value)
  }

  params.set('page', String(pageNumber))

  try {
    const payload = await apiGet(`/platform/users?${params.toString()}`)
    users.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar pengguna.'
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
  writeQuery(router, route, { q: filterQuery.value, status: filterStatus.value })
  await load(1)
  await revealRows()
}

async function resetFilters() {
  filterQuery.value = ''
  filterStatus.value = ''
  await applyFilters()
}

function setStatus(target, status) {
  return apiPatch(`/platform/users/${target.id}/status`, { status })
}

async function activate(target) {
  busyId.value = target.id
  deactivateError.value = null

  try {
    await setStatus(target, 'active')
    toast.ok(`${target.name} diaktifkan.`)
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    toast.err(e instanceof ApiError ? e.message : 'Akun gagal diaktifkan.')
  } finally {
    busyId.value = null
  }
}

async function confirmDeactivate() {
  const target = deactivateTarget.value

  busyId.value = target.id
  deactivateError.value = null

  try {
    await setStatus(target, 'inactive')
    deactivateTarget.value = null
    toast.ok(`${target.name} dinonaktifkan.`)
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    deactivateError.value = e instanceof ApiError ? e.message : 'Akun gagal dinonaktifkan.'
  } finally {
    busyId.value = null
  }
}

onMounted(async () => {
  const initial = readQuery(route, ['q', 'status'])
  filterQuery.value = initial.q ?? ''
  filterStatus.value = initial.status ?? ''

  await load(1)
  await revealRows()
})
</script>

<template>
  <section ref="page" class="platform-view">
    <PageHead
      label="PENGGUNA"
      title="Aktivasi akun"
      lede="Akun yang mendaftar sendiri tanpa organisasi baru bisa masuk setelah diaktifkan di sini. Menonaktifkan akun langsung memutus sesinya yang sedang berjalan."
    />

    <form class="filters" @submit.prevent="applyFilters">
      <div class="field">
        <label for="pu-q" class="filters__label">Cari</label>
        <input
          id="pu-q"
          v-model="filterQuery"
          class="input"
          maxlength="100"
          placeholder="Nama atau email"
        />
      </div>
      <div class="field">
        <label for="pu-status" class="filters__label">Status</label>
        <select id="pu-status" v-model="filterStatus" class="input">
          <option value="">Semua status</option>
          <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="filters__actions">
        <button type="submit" class="btn btn--primary">Terapkan</button>
        <button type="button" class="btn" @click="resetFilters">Reset</button>
      </div>
    </form>

    <LoadingState v-if="loading" label="Memuat daftar pengguna" />

    <ErrorState
      v-else-if="error"
      title="Daftar pengguna gagal dimuat"
      :message="error"
      @retry="load(1)"
    />

    <template v-else-if="users.length">
      <ul class="roster" aria-label="Daftar pengguna platform">
        <li v-for="item in users" :key="item.id" class="row">
          <div class="row__id">
            <p class="row__name">{{ item.name }}</p>
            <p class="row__meta num">{{ item.email }}</p>
            <p class="row__meta num">
              <template v-if="item.created_at">TERDAFTAR {{ fmtDate(item.created_at) }}</template>
              <template v-else>Tanggal daftar tidak tercatat</template>
            </p>
            <p class="row__meta num">SALDO {{ fmtPrice(item.wallet_balance ?? 0) }}</p>
          </div>

          <StatusBadge :tone="STATUS_TONES[item.status] ?? 'neutral'">
            {{ item.status_label ?? item.status }}
          </StatusBadge>

          <div class="row__action">
            <button
              v-if="item.status === 'active'"
              type="button"
              class="btn btn--ghost"
              :disabled="busyId === item.id"
              @click="deactivateTarget = item"
            >
              Nonaktifkan
            </button>
            <button
              v-else
              type="button"
              class="btn btn--primary"
              :disabled="busyId === item.id"
              @click="activate(item)"
            >
              {{ busyId === item.id ? 'Memproses…' : 'Aktifkan' }}
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
      title="Tidak ada akun yang cocok"
      hint="Ubah kata kunci atau status, lalu terapkan lagi."
    />

    <EmptyState
      v-else
      title="Belum ada akun terdaftar"
      hint="Akun yang mendaftar dari halaman publik akan muncul di sini untuk diaktifkan."
    />

    <ConfirmDialog
      v-if="deactivateTarget"
      title="Nonaktifkan akun ini?"
      :message="`${deactivateTarget.name} tidak akan bisa masuk lagi dan sesinya yang sedang berjalan langsung diputus.`"
      confirm-label="Nonaktifkan"
      :busy="busyId === deactivateTarget.id"
      :error="deactivateError"
      @confirm="confirmDeactivate"
      @cancel="deactivateTarget = null"
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

@media (max-width: 48rem) {
  .row {
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
  }
}
</style>
