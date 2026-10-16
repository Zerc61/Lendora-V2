<script setup>
/*
 * Form aset untuk buat dan perbaiki. Dipakai di daftar aset (tambah) dan di
 * detail aset (perbaiki). Kode dan serial number tidak bisa diubah (AS-2,
 * C.6): pada mode perbaiki keduanya terkunci. Foto dikirim multipart.
 */
import { computed, reactive, ref } from 'vue'
import { apiPatch, apiPost, ApiError } from '../lib/http'

const props = defineProps({
  asset: { type: Object, default: null },
  categories: { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
})

const emit = defineEmits(['saved', 'cancel'])

const isEdit = Boolean(props.asset)

const formTitle = computed(() => (isEdit ? 'perbaiki-aset-title' : 'tambah-aset-title'))

const form = reactive({
  name: props.asset?.name ?? '',
  code: props.asset?.code ?? '',
  description: props.asset?.description ?? '',
  category_id: props.asset?.category?.id ?? '',
  location_id: props.asset?.location?.id ?? '',
  serial_number: props.asset?.serial_number ?? '',
  purchase_date: props.asset?.purchase_date ?? '',
  purchase_price: props.asset?.purchase_price == null ? '' : String(props.asset.purchase_price),
  daily_rate: props.asset?.daily_rate == null ? '' : String(props.asset.daily_rate),
  min_days: props.asset?.min_days == null ? '' : String(props.asset.min_days),
  deposit_amount: props.asset?.deposit_amount == null ? '' : String(props.asset.deposit_amount),
  warranty_until: props.asset?.warranty_until ?? '',
  notes: props.asset?.notes ?? '',
})

const photos = ref([])
const sending = ref(false)
const banner = ref(null)
const errors = ref({})

function onPickPhotos(event) {
  photos.value = Array.from(event.target.files ?? [])
}

function fieldError(name) {
  const errs = errors.value
  if (errs[name]) {
    return errs[name][0]
  }
  if (name === 'photos') {
    for (const key of Object.keys(errs)) {
      if (key.startsWith('photos')) {
        return errs[key][0]
      }
    }
  }
  return null
}

async function onSubmit() {
  sending.value = true
  banner.value = null
  errors.value = {}

  const fd = new FormData()
  fd.append('name', form.name)
  fd.append('description', form.description ?? '')
  fd.append('category_id', form.category_id)
  fd.append('location_id', form.location_id || '')
  fd.append('purchase_date', form.purchase_date || '')
  fd.append('purchase_price', form.purchase_price || '')
  fd.append('daily_rate', form.daily_rate || '')
  fd.append('min_days', form.min_days || '')
  fd.append('deposit_amount', form.deposit_amount || '')
  fd.append('warranty_until', form.warranty_until || '')
  fd.append('notes', form.notes ?? '')
  if (!isEdit) {
    fd.append('code', form.code)
    fd.append('serial_number', form.serial_number ?? '')
  }
  for (const file of photos.value) {
    fd.append('photos[]', file)
  }

  try {
    const payload = isEdit
      ? await apiPatch(`/assets/${props.asset.id}`, fd)
      : await apiPost('/assets', fd)
    emit('saved', payload.data)
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      errors.value = error.errors ?? {}
    } else if (error instanceof ApiError) {
      banner.value = error.message
    } else {
      banner.value = 'Tidak bisa terhubung ke server. Coba lagi.'
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="asset-form" :aria-labelledby="formTitle" tabindex="-1">
    <h2 :id="formTitle">{{ isEdit ? 'Perbaiki aset' : 'Tambah aset' }}</h2>

    <p v-if="banner" class="form-error" role="alert">{{ banner }}</p>

    <form @submit.prevent="onSubmit" novalidate>
      <div class="grid">
        <div class="field">
          <label for="af-name">Nama aset</label>
          <input id="af-name" v-model="form.name" class="input" type="text" name="name" required />
          <p v-if="fieldError('name')" class="field-error" role="alert">
            {{ fieldError('name') }}
          </p>
        </div>

        <div class="field">
          <label for="af-code">Kode aset</label>
          <input
            id="af-code"
            v-model="form.code"
            class="input"
            type="text"
            name="code"
            :disabled="isEdit"
            required
          />
          <p v-if="isEdit" class="hint">Kode aset tidak bisa diubah.</p>
          <p v-if="fieldError('code')" class="field-error" role="alert">
            {{ fieldError('code') }}
          </p>
        </div>

        <div class="field">
          <label for="af-category">Kategori</label>
          <select
            id="af-category"
            v-model="form.category_id"
            class="input"
            name="category_id"
            required
          >
            <option value="" disabled>Pilih kategori</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
          <p v-if="fieldError('category_id')" class="field-error" role="alert">
            {{ fieldError('category_id') }}
          </p>
        </div>

        <div class="field">
          <label for="af-location">Lokasi</label>
          <select id="af-location" v-model="form.location_id" class="input" name="location_id">
            <option value="">Tanpa lokasi</option>
            <option v-for="location in locations" :key="location.id" :value="location.id">
              {{ location.name }}
            </option>
          </select>
          <p v-if="fieldError('location_id')" class="field-error" role="alert">
            {{ fieldError('location_id') }}
          </p>
        </div>

        <div class="field field--full">
          <label for="af-description">Deskripsi</label>
          <textarea
            id="af-description"
            v-model="form.description"
            class="input"
            name="description"
            rows="3"
          ></textarea>
          <p v-if="fieldError('description')" class="field-error" role="alert">
            {{ fieldError('description') }}
          </p>
        </div>

        <div v-if="isEdit" class="field">
          <label for="af-serial">Serial number</label>
          <input
            id="af-serial"
            v-model="form.serial_number"
            class="input"
            type="text"
            name="serial_number"
            disabled
          />
          <p class="hint">Serial number tidak bisa diubah.</p>
          <p v-if="fieldError('serial_number')" class="field-error" role="alert">
            {{ fieldError('serial_number') }}
          </p>
        </div>

        <div class="field">
          <label for="af-purchase-date">Tanggal beli</label>
          <input
            id="af-purchase-date"
            v-model="form.purchase_date"
            class="input"
            type="date"
            name="purchase_date"
          />
          <p v-if="fieldError('purchase_date')" class="field-error" role="alert">
            {{ fieldError('purchase_date') }}
          </p>
        </div>

        <div class="field">
          <label for="af-price">Harga beli (Rupiah)</label>
          <input
            id="af-price"
            v-model="form.purchase_price"
            class="input"
            type="number"
            name="purchase_price"
            min="0"
            step="0.01"
          />
          <p v-if="fieldError('purchase_price')" class="field-error" role="alert">
            {{ fieldError('purchase_price') }}
          </p>
        </div>

        <div class="field">
          <label for="af-daily-rate">Tarif harian (Rupiah)</label>
          <input
            id="af-daily-rate"
            v-model="form.daily_rate"
            class="input"
            type="number"
            name="daily_rate"
            min="0"
            step="0.01"
          />
          <p class="hint">Tanpa tarif harian, peralatan ini tidak bisa dipesan customer.</p>
          <p v-if="fieldError('daily_rate')" class="field-error" role="alert">
            {{ fieldError('daily_rate') }}
          </p>
        </div>

        <div class="field">
          <label for="af-min-days">Minimal hari sewa</label>
          <input
            id="af-min-days"
            v-model="form.min_days"
            class="input"
            type="number"
            name="min_days"
            min="1"
            step="1"
          />
          <p class="hint">
            Kosongkan bila tidak ada minimum. Periode lebih pendek tetap dihitung sebanyak ini.
          </p>
          <p v-if="fieldError('min_days')" class="field-error" role="alert">
            {{ fieldError('min_days') }}
          </p>
        </div>

        <div class="field">
          <label for="af-deposit">Deposit (Rupiah)</label>
          <input
            id="af-deposit"
            v-model="form.deposit_amount"
            class="input"
            type="number"
            name="deposit_amount"
            min="0"
            step="0.01"
          />
          <p class="hint">Dicatat pada pemesanan, belum ditagih.</p>
          <p v-if="fieldError('deposit_amount')" class="field-error" role="alert">
            {{ fieldError('deposit_amount') }}
          </p>
        </div>

        <div class="field">
          <label for="af-warranty">Masa garansi sampai</label>
          <input
            id="af-warranty"
            v-model="form.warranty_until"
            class="input"
            type="date"
            name="warranty_until"
          />
          <p v-if="fieldError('warranty_until')" class="field-error" role="alert">
            {{ fieldError('warranty_until') }}
          </p>
        </div>

        <div class="field field--full">
          <label for="af-notes">Catatan internal</label>
          <textarea
            id="af-notes"
            v-model="form.notes"
            class="input"
            name="notes"
            rows="2"
          ></textarea>
          <p v-if="fieldError('notes')" class="field-error" role="alert">
            {{ fieldError('notes') }}
          </p>
        </div>

        <div class="field field--full">
          <label for="af-photos">Foto aset</label>
          <input
            id="af-photos"
            class="input"
            type="file"
            name="photos[]"
            accept="image/png,image/jpeg,image/webp"
            multiple
            @change="onPickPhotos"
          />
          <p class="hint">
            {{
              isEdit
                ? 'Foto yang dipilih ditambahkan ke foto yang sudah ada.'
                : 'Maksimal 10 foto, tipe PNG, JPG, atau WebP.'
            }}
          </p>
          <p v-if="photos.length" class="hint">{{ photos.length }} file baru dipilih.</p>
          <p v-if="fieldError('photos')" class="field-error" role="alert">
            {{ fieldError('photos') }}
          </p>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn btn--primary" :disabled="sending">
          {{ sending ? 'Menyimpan aset' : isEdit ? 'Simpan perubahan' : 'Simpan aset' }}
        </button>
        <button type="button" class="btn" @click="$emit('cancel')">Batal</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.asset-form {
  margin-bottom: 1.5rem;
  padding: 1.25rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-surface);
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 1rem;
}

.field--full {
  grid-column: 1 / -1;
}

.actions {
  display: flex;
  gap: 0.75rem;
  margin-top: 0.5rem;
}
</style>
