<script setup>
/*
 * Kelola kategori (data induk). Hanya admin yang mengubah; daftar dipakai
 * semua role untuk filter. Memakai GET /categories dan mutasi CRUD.
 */
import { onMounted, ref } from 'vue'
import { apiDelete, apiGet, apiPatch, apiPost, ApiError } from '../lib/http'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'
import ConfirmDialog from './ui/ConfirmDialog.vue'

const categories = ref([])
const loading = ref(false)
const error = ref(null)
const banner = ref(null)

const name = ref('')
const description = ref('')
const sending = ref(false)
const fieldErrors = ref({})

const editingId = ref(null)
const editingName = ref('')
const editingDescription = ref('')
const savingEdit = ref(false)
const removeTarget = ref(null)
const removing = ref(false)
const removeError = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const payload = await apiGet('/categories')
    categories.value = payload.data ?? []
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar kategori.'
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
    await apiPost('/categories', {
      name: name.value,
      description: description.value,
    })
    name.value = ''
    description.value = ''
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

function startEdit(category) {
  editingId.value = category.id
  editingName.value = category.name
  editingDescription.value = category.description ?? ''
}

async function saveEdit(category) {
  savingEdit.value = true
  banner.value = null
  try {
    await apiPatch(`/categories/${category.id}`, {
      name: editingName.value,
      description: editingDescription.value,
    })
    editingId.value = null
    await load()
  } catch (e) {
    banner.value = e instanceof ApiError ? e.message : 'Gagal mengganti nama kategori.'
  } finally {
    savingEdit.value = false
  }
}

function remove(category) {
  removeError.value = null
  removeTarget.value = category
}

async function confirmRemove() {
  removing.value = true
  removeError.value = null
  try {
    await apiDelete(`/categories/${removeTarget.value.id}`)
    removeTarget.value = null
    await load()
  } catch (e) {
    removeError.value = e instanceof ApiError ? e.message : 'Gagal menghapus kategori.'
  } finally {
    removing.value = false
  }
}
</script>

<template>
  <section class="manager" aria-labelledby="kategori-title">
    <h2 id="kategori-title">Kategori</h2>

    <p v-if="banner" class="form-error" role="alert">{{ banner }}</p>

    <form class="manager__add" @submit.prevent="add">
      <div class="field">
        <label for="cm-name">Nama kategori</label>
        <input id="cm-name" v-model="name" class="input" type="text" required />
        <p v-if="fieldErrors.name" class="field-error" role="alert">
          {{ fieldErrors.name[0] }}
        </p>
      </div>
      <div class="field">
        <label for="cm-description">Deskripsi</label>
        <input id="cm-description" v-model="description" class="input" type="text" />
        <p v-if="fieldErrors.description" class="field-error" role="alert">
          {{ fieldErrors.description[0] }}
        </p>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="sending">
        {{ sending ? 'Menyimpan' : 'Tambah kategori' }}
      </button>
    </form>

    <LoadingState v-if="loading" label="Memuat kategori" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <ul v-else-if="categories.length" class="manager__list">
      <li v-for="category in categories" :key="category.id" class="manager__row">
        <template v-if="editingId === category.id">
          <div class="manager__edit">
            <label class="visually-hidden" :for="`cm-edit-name-${category.id}`"
              >Nama kategori</label
            >
            <input
              :id="`cm-edit-name-${category.id}`"
              v-model="editingName"
              class="input"
              type="text"
            />
          </div>
          <div class="manager__actions">
            <button type="button" class="btn" :disabled="savingEdit" @click="saveEdit(category)">
              Simpan
            </button>
            <button type="button" class="btn" @click="editingId = null">Batal</button>
          </div>
        </template>
        <template v-else>
          <div class="manager__info">
            <span class="manager__name">{{ category.name }}</span>
            <span v-if="category.description" class="manager__desc text-2">
              {{ category.description }}
            </span>
            <span v-else class="manager__desc text-2">Tanpa deskripsi</span>
          </div>
          <div class="manager__actions">
            <button type="button" class="btn" @click="startEdit(category)">Ganti nama</button>
            <button type="button" class="btn" @click="remove(category)">Hapus</button>
          </div>
        </template>
      </li>
    </ul>

    <EmptyState v-else title="Belum ada kategori" hint="Tambah kategori pertama untuk aset." />

    <ConfirmDialog
      v-if="removeTarget"
      title="Hapus kategori ini?"
      :message="`Kategori '${removeTarget.name}' akan dihapus dari daftar.`"
      confirm-label="Hapus kategori"
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
