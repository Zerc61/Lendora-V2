<script setup>
/*
 * Form serah terima (checkout) untuk loan yang sudah disetujui. Operator
 * mencatat kondisi awal setiap aset sebelum aset keluar. Kondisi buruk
 * (poor/unserviceable) wajib disertai catatan dan minimal satu foto.
 *
 * Presentasi: checklist langkah bernomor per aset dengan garis rule antar
 * blok dan panel ringkasan peminjaman di samping. Field, payload, dan alur
 * submit tidak berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { CONDITION_OPTIONS, fmtDateTime } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const loan = ref(null)
const loading = ref(true)
const error = ref(null)

const items = ref([])
const sending = ref(false)
const submitError = ref(null)
const fieldErrors = ref({})

const page = ref(null)
const motion = usePageMotion(page)

const NOTES_REQUIRED = ['poor', 'unserviceable']

async function load() {
  loading.value = true
  error.value = null
  try {
    const payload = await apiGet(`/loans/${id}`)
    loan.value = payload.data
    items.value = (loan.value.items ?? []).map((item) => ({
      asset_id: item.asset.id,
      asset_name: item.asset.name,
      asset_code: item.asset.code,
      condition: 'good',
      notes: '',
      photos: [],
    }))
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat detail peminjaman.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.item', { stagger: 0.06 })
  motion.reveal('.summary', { delay: 0.1 })
  motion.refresh()
})

function addPhotos(index, event) {
  const files = Array.from(event.target.files ?? [])
  items.value[index].photos.push(...files)
}

function removePhoto(index, photoIndex) {
  items.value[index].photos.splice(photoIndex, 1)
}

const canSubmit = computed(() => !sending.value && items.value.length > 0)

async function submit() {
  sending.value = true
  submitError.value = null
  fieldErrors.value = {}

  const fd = new FormData()
  items.value.forEach((item, index) => {
    fd.append(`items[${index}][asset_id]`, String(item.asset_id))
    fd.append(`items[${index}][condition]`, item.condition)
    fd.append(`items[${index}][notes]`, item.notes || '')
    item.photos.forEach((file) => {
      fd.append(`items[${index}][photos][]`, file)
    })
  })

  try {
    await apiPost(`/loans/${id}/checkout`, fd)
    router.replace({ name: 'loan-detail', params: { id } })
  } catch (e) {
    if (e instanceof ApiError) {
      submitError.value = e.message
      fieldErrors.value = e.errors ?? {}
    } else {
      submitError.value = 'Gagal mengirim serah terima.'
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section ref="page" class="checkout">
    <PageHead
      label="PEMINJAMAN"
      title="Serah Terima Aset"
      lede="Catat kondisi awal setiap aset sebelum diserahkan kepada peminjam. Kondisi buruk wajib disertai catatan dan minimal satu foto."
    />

    <LoadingState v-if="loading" label="Memuat detail peminjaman" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="loan">
      <p v-if="submitError" class="form-error" role="alert">{{ submitError }}</p>

      <div class="layout">
        <form class="checklist" @submit.prevent="submit">
          <fieldset v-for="(item, index) in items" :key="item.asset_id" class="item">
            <legend class="item__legend">
              <span class="item__name">{{ item.asset_name }}</span>
              <span class="item__code num">Kode {{ item.asset_code }}</span>
            </legend>

            <div class="field">
              <label :for="`condition-${index}`">Kondisi</label>
              <select :id="`condition-${index}`" v-model="item.condition" class="input" required>
                <option v-for="cond in CONDITION_OPTIONS" :key="cond.value" :value="cond.value">
                  {{ cond.label }}
                </option>
              </select>
              <span v-if="fieldErrors[`items.${index}.condition`]" class="field-error">{{
                fieldErrors[`items.${index}.condition`][0]
              }}</span>
            </div>

            <div class="field">
              <label :for="`notes-${index}`">
                Catatan
                <span v-if="NOTES_REQUIRED.includes(item.condition)" class="req"
                  >(wajib untuk kondisi buruk)</span
                >
              </label>
              <textarea
                :id="`notes-${index}`"
                v-model="item.notes"
                class="input"
                rows="2"
                maxlength="5000"
                :required="NOTES_REQUIRED.includes(item.condition)"
              ></textarea>
              <span v-if="fieldErrors[`items.${index}.notes`]" class="field-error">{{
                fieldErrors[`items.${index}.notes`][0]
              }}</span>
            </div>

            <div class="field">
              <label :for="`photos-${index}`">
                Foto
                <span v-if="NOTES_REQUIRED.includes(item.condition)" class="req"
                  >(minimal 1 untuk kondisi buruk)</span
                >
              </label>
              <input
                :id="`photos-${index}`"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                class="input"
                @change="addPhotos(index, $event)"
              />
              <span v-if="fieldErrors[`items.${index}.photos`]" class="field-error">{{
                fieldErrors[`items.${index}.photos`][0]
              }}</span>
              <ul v-if="item.photos.length" class="photo-list">
                <li v-for="(photo, photoIndex) in item.photos" :key="photoIndex" class="photo-item">
                  <span class="text-2">{{ photo.name }}</span>
                  <button
                    type="button"
                    class="btn btn--small"
                    @click="removePhoto(index, photoIndex)"
                  >
                    Hapus
                  </button>
                </li>
              </ul>
            </div>
          </fieldset>

          <div class="act-row rule-top">
            <button type="submit" class="btn btn--primary" :disabled="!canSubmit">
              {{ sending ? 'Mengirim' : 'Serahkan Aset' }}
            </button>
            <button type="button" class="btn" @click="router.back()">Batal</button>
          </div>
        </form>

        <aside class="summary panel panel--alt" aria-label="Ringkasan peminjaman">
          <p class="index-label">RINGKASAN</p>
          <p class="summary__borrower">
            Peminjam: <strong>{{ loan.borrower?.name ?? 'Tidak tercatat' }}</strong>
          </p>
          <dl class="summary__list">
            <div>
              <dt>Periode</dt>
              <dd>{{ fmtDateTime(loan.starts_at) }} – {{ fmtDateTime(loan.ends_at) }}</dd>
            </div>
            <div>
              <dt>Jumlah aset</dt>
              <dd class="num">{{ items.length }}</dd>
            </div>
          </dl>
          <p class="summary__foot text-2">
            Setelah diserahkan, aset berstatus sedang dipinjam dan menunggu pengembalian.
          </p>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.checkout {
  display: grid;
  gap: 1.75rem;
}

.layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 20rem;
  align-items: start;
  gap: 2.5rem;
}

.checklist {
  display: grid;
  gap: 0;
}

/* Checklist langkah: garis rule antar blok aset. */
.item {
  margin: 0;
  padding: 1.25rem 0 1.75rem;
  border: 0;
  border-bottom: 1px solid var(--ln-border);
}

.item:first-child {
  padding-top: 0;
}

.item__legend {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 0.75rem;
  width: 100%;
  padding: 0 0 0.875rem;
}

.item__name {
  font-family: var(--ln-font-display);
  font-size: 1.25rem;
  font-weight: 500;
  letter-spacing: -0.03em;
}

.item__code {
  color: var(--ln-text-2);
  font-size: 0.6875rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.req {
  color: var(--ln-text-2);
  font-weight: 400;
  font-size: 0.875rem;
}

.photo-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: grid;
  border-top: 1px solid var(--ln-border);
}

.photo-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  min-height: 44px;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--ln-border);
  font-size: 0.875rem;
}

.act-row {
  display: flex;
  gap: 0.75rem;
}

.rule-top {
  padding-top: 1.5rem;
}

.field-error {
  display: block;
  margin-top: 0.25rem;
  color: var(--ln-err);
  font-size: 0.8125rem;
}

/* Panel ringkasan di samping. */
.summary {
  position: sticky;
  top: 2rem;
  display: grid;
  gap: 0.75rem;
}

.summary__borrower {
  margin: 0;
  font-size: 0.9375rem;
}

.summary__list {
  margin: 0;
  display: grid;
}

.summary__list > div {
  padding: 0.625rem 0;
  border-top: 1px solid var(--ln-border);
}

.summary__list dt {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.summary__list dd {
  margin: 0.25rem 0 0;
  font-weight: 600;
  font-size: 0.9375rem;
}

.summary__foot {
  margin: 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--ln-border);
  font-size: 0.8125rem;
  line-height: 1.6;
}

@media (max-width: 64rem) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 2rem;
  }

  .summary {
    position: static;
  }
}
</style>
