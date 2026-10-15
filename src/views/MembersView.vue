<script setup>
/*
 * Manajemen anggota organisasi (admin).
 * Menampilkan daftar membership dengan filter role dan status,
 * serta aksi ubah role dan ubah status (aktifkan/nonaktifkan).
 *
 * Presentasi editorial: kepala halaman PageHead dan daftar baris bergaris
 * rule dengan avatar inisial mono, tag peran, serta badge status. State,
 * filter, API, dan handler tidak berubah (lib/motion.js untuk gerak).
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch, apiPost, apiDelete, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { auth } from '../lib/auth'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'
import StatusBadge from '../components/StatusBadge.vue'
import PageHead from '../components/ui/PageHead.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'

const ROLE_OPTIONS = [
  { value: 'borrower', label: 'Peminjam' },
  { value: 'operator', label: 'Operator' },
  { value: 'admin', label: 'Admin' },
]

/* Status membership (bukan status akun): 'invited' = menunggu aktivasi (OD-29). */
const STATUS_OPTIONS = [
  { value: 'invited', label: 'Menunggu aktivasi' },
  { value: 'active', label: 'Aktif' },
  { value: 'inactive', label: 'Nonaktif' },
]

const memberships = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const filterRole = ref('')
const filterStatus = ref('')
const filterQuery = ref('')

const route = useRoute()
const router = useRouter()

const isAdmin = computed(() => auth.role === 'admin')

/* Tambah anggota: admin menautkan akun yang sudah terdaftar lewat email. */
const addOpen = ref(false)
const addForm = reactive({ email: '', role: 'borrower' })
const addBusy = ref(false)
const addError = ref(null)
const addFieldErrors = ref({})

/* Aksi per baris: reset password (nilai baru tampil sekali) dan hapus anggota. */
const resetTarget = ref(null)
const resetBusy = ref(false)
const resetError = ref(null)
const resetResult = ref(null)
const removeTarget = ref(null)
const removeBusy = ref(false)
const removeError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null
  const params = new URLSearchParams()
  if (filterRole.value) params.set('role', filterRole.value)
  if (filterStatus.value) params.set('status', filterStatus.value)
  if (filterQuery.value) params.set('q', filterQuery.value)
  params.set('page', String(pageNumber))
  try {
    const payload = await apiGet(`/memberships?${params.toString()}`)
    memberships.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar anggota.'
  } finally {
    loading.value = false
  }
}

async function submitAdd() {
  addBusy.value = true
  addError.value = null
  addFieldErrors.value = {}
  try {
    const payload = await apiPost('/memberships', { ...addForm })
    addOpen.value = false
    addForm.email = ''
    addForm.role = 'borrower'
    toast.ok(
      `${payload.data?.user?.name ?? 'Anggota'} ditambahkan sebagai ${payload.data?.role_label ?? 'anggota'}.`,
    )
    await load(1)
  } catch (e) {
    if (e instanceof ApiError) {
      addError.value = e.errors ? 'Periksa kembali isian di bawah.' : e.message
      addFieldErrors.value = e.errors ?? {}
    } else {
      addError.value = 'Gagal menambah anggota.'
    }
  } finally {
    addBusy.value = false
  }
}

async function confirmReset() {
  resetBusy.value = true
  resetError.value = null
  try {
    const payload = await apiPost(`/memberships/${resetTarget.value.id}/reset-password`)
    resetResult.value = {
      name: resetTarget.value.user?.name ?? '',
      password: payload.password,
    }
    resetTarget.value = null
  } catch (e) {
    resetError.value = e instanceof ApiError ? e.message : 'Gagal mereset password.'
  } finally {
    resetBusy.value = false
  }
}

async function confirmRemove() {
  removeBusy.value = true
  removeError.value = null
  try {
    await apiDelete(`/memberships/${removeTarget.value.id}`)
    const name = removeTarget.value.user?.name ?? 'Anggota'
    removeTarget.value = null
    toast.ok(`${name} dihapus dari organisasi.`)
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    removeError.value = e instanceof ApiError ? e.message : 'Gagal menghapus anggota.'
  } finally {
    removeBusy.value = false
  }
}

async function copyPassword(password) {
  try {
    await navigator.clipboard.writeText(password)
    toast.ok('Password disalin ke papan klip.')
  } catch {
    toast.err('Gagal menyalin. Salin manual dari kotak di atas.')
  }
}

// Inline edit: per baris punya state editing tersendiri
const editing = ref({}) // { [id]: { role, status, saving, error } }

function startEdit(membership) {
  editing.value[membership.id] = {
    role: membership.role,
    status: membership.status,
    saving: false,
    error: null,
  }
}

function cancelEdit(id) {
  delete editing.value[id]
}

/* Nonaktifkan = sesi user langsung berakhir; minta konfirmasi dulu. */
const deactivateTarget = ref(null)
const deactivateState = ref(null)

async function saveEdit(membership) {
  const state = editing.value[membership.id]
  if (!state) return

  if (state.status === 'inactive' && membership.status !== 'inactive') {
    deactivateTarget.value = membership
    deactivateState.value = state
    return
  }

  await persistEdit(membership, state)
}

function cancelDeactivate() {
  deactivateTarget.value = null
  deactivateState.value = null
}

async function confirmDeactivate() {
  const membership = deactivateTarget.value
  const state = deactivateState.value
  deactivateTarget.value = null
  deactivateState.value = null
  if (membership && state) {
    await persistEdit(membership, state)
  }
}

async function persistEdit(membership, state) {
  state.saving = true
  state.error = null
  try {
    await apiPatch(`/memberships/${membership.id}`, {
      role: state.role,
      status: state.status,
    })
    toast.ok(`Keanggotaan ${membership.user?.name ?? ''} diperbarui.`)
    cancelEdit(membership.id)
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    state.error = e instanceof ApiError ? e.message : 'Gagal menyimpan.'
  } finally {
    state.saving = false
  }
}

function applyFilters() {
  writeQuery(router, route, {
    role: filterRole.value,
    status: filterStatus.value,
    q: filterQuery.value.trim(),
  })
  load(1)
}

function resetFilters() {
  filterRole.value = ''
  filterStatus.value = ''
  filterQuery.value = ''
  applyFilters()
}

// Presentasi saja: nada badge StatusBadge dan inisial avatar mono.
function statusTone(status) {
  if (status === 'active') return 'ok'
  if (status === 'invited') return 'warn'
  return 'neutral'
}

function initials(name) {
  if (!name) return '-'
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}

onMounted(async () => {
  const initial = readQuery(route, ['role', 'status', 'q'])
  filterRole.value = ROLE_OPTIONS.some((option) => option.value === initial.role)
    ? initial.role
    : ''
  filterStatus.value = STATUS_OPTIONS.some((option) => option.value === initial.status)
    ? initial.status
    : ''
  filterQuery.value = initial.q ?? ''

  await load()
  await nextTick()
  motion.reveal('.member', { stagger: 0.05 })
  motion.refresh()
})

// Pelayoutan daftar berubah setelah muat/halaman baru; hitung ulang posisi gerak.
watch(memberships, () => {
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="members">
    <PageHead
      label="ANGGOTA"
      title="Daftar anggota"
      lede="Daftar seluruh anggota organisasi aktif. Admin dapat mengubah role dan status."
    />

    <div v-if="resetResult" class="notice notice--code" role="status">
      <span>
        Password baru untuk <strong>{{ resetResult.name }}</strong> (tampil sekali ini, sampaikan
        langsung):
      </span>
      <code class="num">{{ resetResult.password }}</code>
      <span class="notice__actions">
        <button type="button" class="btn btn--small" @click="copyPassword(resetResult.password)">
          Salin
        </button>
        <button type="button" class="btn btn--ghost btn--small" @click="resetResult = null">
          Tutup
        </button>
      </span>
    </div>

    <div v-if="isAdmin" class="add-bar">
      <button v-if="!addOpen" type="button" class="btn" @click="addOpen = true">
        Tambah anggota
      </button>

      <form v-else class="add-form" @submit.prevent="submitAdd">
        <div class="field">
          <label for="m-add-email">Email akun yang sudah terdaftar</label>
          <input
            id="m-add-email"
            v-model="addForm.email"
            class="input"
            type="email"
            maxlength="255"
            required
            placeholder="nama@example.org"
          />
          <span v-if="addFieldErrors.email" class="field-error">{{ addFieldErrors.email[0] }}</span>
        </div>
        <div class="field">
          <label for="m-add-role">Role</label>
          <select id="m-add-role" v-model="addForm.role" class="input">
            <option v-for="opt in ROLE_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
        <div class="add-form__actions">
          <button type="submit" class="btn btn--primary" :disabled="addBusy">
            {{ addBusy ? 'Menambahkan…' : 'Tambahkan' }}
          </button>
          <button type="button" class="btn" @click="addOpen = false">Batal</button>
        </div>
        <p v-if="addError" class="form-error" role="alert">{{ addError }}</p>
      </form>
    </div>

    <form class="filters" @submit.prevent="applyFilters">
      <div class="field">
        <label for="m-q" class="filters__label">Cari</label>
        <input
          id="m-q"
          v-model="filterQuery"
          class="input"
          maxlength="100"
          placeholder="Nama atau email"
        />
      </div>
      <div class="field">
        <label for="m-role" class="filters__label">Role</label>
        <select id="m-role" v-model="filterRole" class="input">
          <option value="">Semua role</option>
          <option v-for="opt in ROLE_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="field">
        <label for="m-status" class="filters__label">Status</label>
        <select id="m-status" v-model="filterStatus" class="input">
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

    <LoadingState v-if="loading" label="Memuat daftar anggota" />

    <ErrorState v-else-if="error" :message="error" @retry="() => load()" />

    <template v-else-if="memberships.length">
      <ul class="roster" aria-label="Daftar anggota">
        <li
          v-for="item in memberships"
          :key="item.id"
          class="member"
          :class="{ 'member--editing': editing[item.id] }"
        >
          <span class="member__avatar num" aria-hidden="true">
            {{ initials(item.user?.name) }}
          </span>

          <div class="member__id">
            <p class="member__name">{{ item.user?.name ?? '-' }}</p>
            <p class="member__meta num">{{ item.user?.email ?? '-' }}</p>
            <p class="member__meta num">
              <template v-if="item.joined_at">
                BERGABUNG
                {{
                  new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(
                    new Date(item.joined_at),
                  )
                }}
              </template>
              <template v-else>Belum aktif</template>
            </p>
          </div>

          <div class="member__cell">
            <span class="member__cell-label" aria-hidden="true">PERAN</span>
            <template v-if="editing[item.id]">
              <label :for="`role-${item.id}`" class="visually-hidden">Role</label>
              <select
                :id="`role-${item.id}`"
                v-model="editing[item.id].role"
                class="input input--sm"
              >
                <option v-for="opt in ROLE_OPTIONS" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </template>
            <span v-else class="tag">{{ item.role_label }}</span>
          </div>

          <div class="member__cell">
            <span class="member__cell-label" aria-hidden="true">STATUS</span>
            <template v-if="editing[item.id]">
              <label :for="`status-${item.id}`" class="visually-hidden">Status</label>
              <select
                :id="`status-${item.id}`"
                v-model="editing[item.id].status"
                class="input input--sm"
              >
                <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </template>
            <StatusBadge v-else :tone="statusTone(item.status)">
              {{ item.status_label }}
            </StatusBadge>
          </div>

          <div class="member__actions">
            <template v-if="editing[item.id]">
              <p v-if="editing[item.id].error" class="field-error" role="alert">
                {{ editing[item.id].error }}
              </p>
              <div class="row-actions">
                <button
                  type="button"
                  class="btn btn--primary"
                  :disabled="editing[item.id].saving"
                  @click="saveEdit(item)"
                >
                  {{ editing[item.id].saving ? 'Menyimpan...' : 'Simpan' }}
                </button>
                <button type="button" class="btn" @click="cancelEdit(item.id)">Batal</button>
              </div>
            </template>
            <template v-else>
              <div class="row-actions">
                <button type="button" class="btn btn--ghost" @click="startEdit(item)">Edit</button>
                <button
                  v-if="isAdmin"
                  type="button"
                  class="btn btn--ghost"
                  @click="resetTarget = item"
                >
                  Reset password
                </button>
                <button
                  v-if="isAdmin"
                  type="button"
                  class="btn btn--ghost"
                  @click="removeTarget = item"
                >
                  Hapus
                </button>
              </div>
              <span v-if="!isAdmin" class="member__hint text-2">Tampilan baca saja</span>
            </template>
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
        @page="load"
      />
    </template>

    <EmptyState
      v-else
      title="Belum ada anggota"
      hint="Belum ada anggota yang terdaftar di organisasi ini."
    />

    <ConfirmDialog
      v-if="resetTarget"
      title="Reset password anggota ini?"
      :message="`Password ${resetTarget.user?.name ?? ''} akan diganti. Nilai baru tampil sekali di layar ini, sesinya langsung berakhir, dan ia harus login ulang.`"
      confirm-label="Reset password"
      :busy="resetBusy"
      :error="resetError"
      @confirm="confirmReset"
      @cancel="resetTarget = null"
    />

    <ConfirmDialog
      v-if="removeTarget"
      title="Hapus anggota ini?"
      :message="`${removeTarget.user?.name ?? ''} dilepas dari organisasi dan sesinya dihentikan. Akunnya tetap ada dan bisa ditambahkan kembali nanti.`"
      confirm-label="Hapus anggota"
      :busy="removeBusy"
      :error="removeError"
      @confirm="confirmRemove"
      @cancel="removeTarget = null"
    />

    <ConfirmDialog
      v-if="deactivateTarget"
      title="Nonaktifkan anggota ini?"
      :message="`${deactivateTarget.user?.name ?? ''} tidak bisa login sampai diaktifkan kembali. Sesi yang sedang berjalan langsung berakhir.`"
      confirm-label="Nonaktifkan"
      :busy="deactivateState?.saving ?? false"
      @confirm="confirmDeactivate"
      @cancel="cancelDeactivate"
    />
  </section>
</template>

<style scoped>
.members {
  display: grid;
  gap: 2.5rem;
}

.notice {
  margin: 0;
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--ln-ok);
  background: var(--ln-ok-bg);
  color: var(--ln-ok);
}

/* Panel password hasil reset: kode mono + aksi salin. */
.notice--code {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.notice--code code {
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--ln-ok);
  background: var(--ln-bg);
  color: var(--ln-text);
  font-size: 0.875rem;
  letter-spacing: 0.04em;
}

.notice__actions {
  display: flex;
  gap: 0.5rem;
  margin-left: auto;
}

/* Bar tambah anggota. */
.add-bar {
  display: grid;
  gap: 0.75rem;
  justify-items: start;
}

.add-form {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 0.75rem 1rem;
  padding: 1.25rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-surface);
}

.add-form .field {
  margin: 0;
}

.add-form__actions {
  display: flex;
  gap: 0.5rem;
  align-items: end;
}

.add-form .form-error,
.add-form .add-form__actions {
  grid-column: 1 / -1;
}

.member__hint {
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
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

/* Daftar anggota: baris editorial bergaris rule. */
.roster {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.member {
  display: grid;
  grid-template-columns: 44px minmax(0, 1fr) auto auto auto;
  align-items: center;
  gap: 1rem 1.5rem;
  min-height: 5rem;
  padding: 1rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.member--editing {
  align-items: start;
}

.member--editing .member__actions {
  grid-column: 1 / -1;
  padding-left: calc(44px + 1.5rem);
}

.member__avatar {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
  color: var(--ln-text);
  font-size: 0.8125rem;
  font-weight: 600;
  letter-spacing: 0.06em;
}

.member__id {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.member__name {
  margin: 0;
  font-weight: 600;
}

.member__meta {
  margin: 0;
  overflow-wrap: anywhere;
  color: var(--ln-text-2);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.member__cell {
  display: grid;
  gap: 0.375rem;
  justify-items: start;
}

.member__cell-label {
  color: var(--ln-n400);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
}

.tag {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--ln-border-strong);
  color: var(--ln-text);
  font-family: var(--ln-font-mono);
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  white-space: nowrap;
}

.member__actions {
  display: grid;
  gap: 0.375rem;
  justify-items: end;
}

.row-actions {
  display: flex;
  gap: 0.5rem;
}

.input--sm {
  min-height: 44px;
  padding: 0.375rem 0.5rem;
  font-size: 0.875rem;
}

@media (max-width: 60rem) {
  .member {
    grid-template-columns: 44px minmax(0, 1fr) auto;
  }

  .member__cell,
  .member__actions {
    grid-column: 2 / -1;
    justify-items: start;
  }
}

@media (max-width: 48rem) {
  .filters {
    flex-direction: column;
    align-items: stretch;
  }

  .add-form {
    grid-template-columns: minmax(0, 1fr);
  }

  .member {
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 0.75rem 1rem;
  }

  .member__cell,
  .member__actions {
    grid-column: 2;
  }

  .member--editing .member__actions {
    padding-left: 0;
  }
}
</style>
