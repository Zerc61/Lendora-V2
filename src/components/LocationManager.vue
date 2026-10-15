<script setup>
/*
 * Kelola lokasi (data induk, hierarki maksimal 3 tingkat). Hanya admin yang
 * mengubah; daftar dipakai semua role untuk filter dan form aset.
 */
import { computed, onMounted, ref } from 'vue'
import { apiDelete, apiGet, apiPatch, apiPost, ApiError } from '../lib/http'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'
import ConfirmDialog from './ui/ConfirmDialog.vue'

const locations = ref([])
const loading = ref(false)
const error = ref(null)
const banner = ref(null)

const name = ref('')
const parentId = ref('')
const sending = ref(false)
const fieldErrors = ref({})

const editingId = ref(null)
const editingName = ref('')
const savingEdit = ref(false)
const removeTarget = ref(null)
const removing = ref(false)
const removeError = ref(null)

const parentOptions = computed(() =>
  locations.value.filter((location) => location.id !== editingId.value),
)

function parentNameOf(location) {
  return locations.value.find((item) => item.id === location.parent_id)?.name ?? null
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const payload = await apiGet('/locations')
    locations.value = payload.data ?? []
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar lokasi.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function add() {
  sending.value = true
  banner.value = null
  fieldErrors.value = {}
  try {
    await apiPost('/locations', {
      name: name.value,
      parent_id: parentId.value || null,
    })
    name.value = ''
    parentId.value = ''
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors ?? {}
    } else if (e instanceof ApiError) {
      banner.value = e.message
    } else {
      banner.value = 'Tidak bisa terhubung ke server.'
    }
  } finally {
    sending.value = false
  }
}

function startEdit(location) {
  editingId.value = location.id
  editingName.value = location.name
}

async function saveEdit(location) {
  savingEdit.value = true
  banner.value = null
  try {
    await apiPatch(`/locations/${location.id}`, { name: editingName.value })
    editingId.value = null
    await load()
  } catch (e) {
    banner.value = e instanceof ApiError ? e.message : 'Gagal mengganti nama lokasi.'
  } finally {
    savingEdit.value = false
  }
}

function remove(location) {
  removeError.value = null
  removeTarget.value = location
}

async function confirmRemove() {
  removing.value = true
  removeError.value = null
  try {
    await apiDelete(`/locations/${removeTarget.value.id}`)
    removeTarget.value = null
    await load()
  } catch (e) {
    removeError.value = e instanceof ApiError ? e.message : 'Gagal menghapus lokasi.'
  } finally {
    removing.value = false
  }
}
</script>

<template>
  <section class="manager" aria-labelledby="lokasi-title">
    <h2 id="lokasi-title">Lokasi</h2>

    <p v-if="banner" class="form-error" role="alert">{{ banner }}</p>

    <form class="manager__add" @submit.prevent="add">
      <div class="field">
        <label for="lm-name">Nama lokasi</label>
        <input id="lm-name" v-model="name" class="input" type="text" required />
        <p v-if="fieldErrors.name" class="field-error" role="alert">
          {{ fieldErrors.name[0] }}
        </p>
      </div>
      <div class="field">
        <label for="lm-parent">Induk</label>
        <select id="lm-parent" v-model="parentId" class="input">
          <option value="">Tidak ada (tingkat 1)</option>
          <option v-for="location in parentOptions" :key="location.id" :value="location.id">
            {{ location.name }}
          </option>
        </select>
        <p v-if="fieldErrors.parent_id" class="field-error" role="alert">
          {{ fieldErrors.parent_id[0] }}
        </p>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="sending">
        {{ sending ? 'Menyimpan' : 'Tambah lokasi' }}
      </button>
    </form>

    <LoadingState v-if="loading" label="Memuat lokasi" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <ul v-else-if="locations.length" class="manager__list">
      <li v-for="location in locations" :key="location.id" class="manager__row">
        <template v-if="editingId === location.id">
          <div class="manager__edit">
            <label class="visually-hidden" :for="`lm-edit-name-${location.id}`">Nama lokasi</label>
            <input
              :id="`lm-edit-name-${location.id}`"
              v-model="editingName"
              class="input"
              type="text"
            />
          </div>
          <div class="manager__actions">
            <button type="button" class="btn" :disabled="savingEdit" @click="saveEdit(location)">
              Simpan
            </button>
            <button type="button" class="btn" @click="editingId = null">Batal</button>
          </div>
        </template>
        <template v-else>
          <div class="manager__info">
            <span class="manager__name">{{ location.name }}</span>
            <span class="manager__desc text-2">
              Tingkat {{ location.level
              }}<template v-if="parentNameOf(location)">
                , di dalam {{ parentNameOf(location) }}</template
              >
            </span>
          </div>
          <div class="manager__actions">
            <button type="button" class="btn" @click="startEdit(location)">Ganti nama</button>
            <button type="button" class="btn" @click="remove(location)">Hapus</button>
          </div>
        </template>
      </li>
    </ul>

    <EmptyState v-else title="Belum ada lokasi" hint="Tambah lokasi penyimpanan aset." />

    <ConfirmDialog
      v-if="removeTarget"
      title="Hapus lokasi ini?"
      :message="`Lokasi '${removeTarget.name}' akan dihapus dari daftar. Aset yang menunjuk lokasi ini perlu dialihkan dulu.`"
      confirm-label="Hapus lokasi"
      :busy="removing"
      :error="removeError"
      @confirm="confirmRemove"
      @cancel="removeTarget = null"
    />
  </section>
</template>

<style scoped>
.manager {
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 2px solid var(--ln-border-strong);
}

.manager__add {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
  gap: 0 1rem;
  align-items: end;
  margin-bottom: 1rem;
}

.manager__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.manager__row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  padding: 0.625rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.manager__info {
  display: grid;
  gap: 0.125rem;
  min-width: 0;
}

.manager__name {
  font-weight: 600;
}

.manager__desc {
  font-size: 0.8125rem;
}

.manager__actions {
  display: flex;
  gap: 0.5rem;
}

.manager__edit {
  width: 100%;
  max-width: 20rem;
}

@media (max-width: 40rem) {
  .manager__add {
    grid-template-columns: 1fr;
  }

  .manager__row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
