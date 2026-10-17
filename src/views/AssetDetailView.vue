<script setup>
/*
 * Detail aset untuk semua role. Bagian yang tampil mengikuti role saat ini:
 * - Peminjam: hanya info publik + pemeriksa ketersediaan.
 * - Operator dan admin: ditambah data internal (serial, beli, garansi) dan
 *   aksi perbaiki. Admin juga dapat menghentikan pemakaian dan menghapus.
 *
 * Presentasi editorial: PageHead berisi judul + kode aset, galeri dan panel
 * di kolom utama, ringkasan metadata sebagai panel bergaris yang sticky di
 * desktop, rail progres yang terisi mengikuti gulir, dan reveal untuk blok
 * metadata. Seluruh endpoint, permission, dan alur form tidak berubah.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiDelete, apiGet, apiPost, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { auth } from '../lib/auth'
import { fmtDate, fmtDateTime, fmtPrice } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import PageHead from '../components/ui/PageHead.vue'
import StatusBadge from '../components/StatusBadge.vue'
import AssetForm from '../components/AssetForm.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import ConfirmDialog from '../components/ui/ConfirmDialog.vue'

const route = useRoute()
const router = useRouter()

const asset = ref(null)
const categories = ref([])
const locations = ref([])
const loading = ref(true)
const error = ref(null)
const editOpen = ref(false)

const page = ref(null)
const motion = usePageMotion(page)
const priceEl = ref(null)

const LIFECYCLE_TONES = {
  active: 'ok',
  awaiting_inspection: 'info',
  under_maintenance: 'warn',
  retired: 'neutral',
}

const STATUS_LABELS = {
  requested: 'Diminta',
  approved: 'Disetujui',
  checked_out: 'Sedang dipinjam',
  returned: 'Dikembalikan',
  cancelled: 'Dibatalkan',
  rejected: 'Ditolak',
}

const isManagement = computed(() => auth.role === 'operator' || auth.role === 'admin')
const isAdmin = computed(() => auth.role === 'admin')
const backTo = computed(() => (isManagement.value ? 'assets' : 'catalog'))
const backLabel = computed(() => (isManagement.value ? 'daftar aset' : 'cari aset'))
const photos = computed(() => asset.value?.photos ?? [])
const mainPhoto = computed(() => photos.value[0] ?? null)
const lifecycleTone = computed(() => LIFECYCLE_TONES[asset.value?.lifecycle_status] ?? 'neutral')
const isRetired = computed(() => asset.value?.lifecycle_status === 'retired')

const id = Number(route.params.id)

async function load() {
  loading.value = true
  error.value = null
  try {
    asset.value = (await apiGet(`/assets/${id}`)).data
    if (isManagement.value) {
      const [categoryPayload, locationPayload] = await Promise.all([
        apiGet('/categories'),
        apiGet('/locations'),
      ])
      categories.value = categoryPayload.data ?? []
      locations.value = locationPayload.data ?? []
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat detail aset.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.detail__gallery', { y: 16, duration: 0.5 })
  motion.reveal('.summary__list > div', { stagger: 0.05 })
  motion.reveal('.detail__form, .detail__panels > section', { stagger: 0.08 })
  motion.scrubProgress('.detail__rail-fill', '.detail__layout', {
    start: 'top 80%',
    end: 'bottom 55%',
  })
  if (priceEl.value && asset.value?.purchase_price != null) {
    motion.countTo(priceEl.value, asset.value.purchase_price, {
      format: (value) => fmtPrice(value),
    })
  }
  motion.refresh()
})

async function onSaved() {
  editOpen.value = false
  toast.ok('Perubahan disimpan.')
  await load()
}

// --- Pemeriksa ketersediaan ---

const startsAt = ref('')
const endsAt = ref('')
const checking = ref(false)
const availability = ref(null)
const checkError = ref(null)

async function checkAvailability() {
  if (!startsAt.value || !endsAt.value) {
    return
  }
  checking.value = true
  checkError.value = null
  availability.value = null

  const params = new URLSearchParams({
    starts_at: startsAt.value,
    ends_at: endsAt.value,
  })

  try {
    const payload = await apiGet(`/assets/${id}/availability?${params.toString()}`)
    availability.value = payload.data ?? null
  } catch (e) {
    checkError.value = e instanceof ApiError ? e.message : 'Gagal memeriksa ketersediaan.'
  } finally {
    checking.value = false
  }
}

// --- Penghentian pemakaian (admin) ---

const retireOpen = ref(false)
const retireReason = ref('')
const retiring = ref(false)
const retireError = ref(null)

// --- Request loan ---
function requestLoan(assetId, startsAt = null, endsAt = null) {
  const data = {
    assets: [assetId], // Always an array of IDs
    starts_at: startsAt,
    ends_at: endsAt,
  }
  sessionStorage.setItem('loan-request-data', JSON.stringify(data))
  router.push({ name: 'loan-create' })
}

// --- Report issue ---
function reportIssue(assetId) {
  const data = {
    asset: assetId,
  }
  sessionStorage.setItem('issue-request-data', JSON.stringify(data))
  router.push({ name: 'issue-create' })
}

// --- Create ticket ---
function createTicket(assetId) {
  const data = {
    asset: assetId,
  }
  sessionStorage.setItem('ticket-request-data', JSON.stringify(data))
  router.push({ name: 'ticket-create' })
}

/* Penghapusan aset (admin) lewat dialog konfirmasi. */
const removeOpen = ref(false)
const removing = ref(false)
const removeError = ref(null)

async function submitRetire() {
  retiring.value = true
  retireError.value = null
  try {
    const payload = await apiPost('/assets/' + id + '/retire', {
      reason: retireReason.value,
    })
    asset.value = payload.data
    retireOpen.value = false
    retireReason.value = ''
    toast.ok('Aset dihentikan pemakaiannya.')
  } catch (e) {
    retireError.value = e instanceof ApiError ? e.message : 'Gagal menghentikan pemakaian.'
  } finally {
    retiring.value = false
  }
}

function removeAsset() {
  removeError.value = null
  removeOpen.value = true
}

async function confirmRemoveAsset() {
  removing.value = true
  removeError.value = null
  try {
    await apiDelete(`/assets/${id}`)
    toast.ok('Aset dihapus.')
    router.replace({ name: 'assets' })
  } catch (e) {
    removeError.value = e instanceof ApiError ? e.message : 'Gagal menghapus aset.'
  } finally {
    removing.value = false
  }
}
</script>

<template>
  <section ref="page" class="detail">
    <RouterLink :to="{ name: backTo }" class="back">&larr; Kembali ke {{ backLabel }}</RouterLink>

    <LoadingState v-if="loading" label="Memuat detail aset" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="asset">
      <PageHead
        :label="`ASET · ${asset.code}`"
        :title="asset.name"
        :lede="asset.description || null"
      >
        <template #actions>
          <template v-if="isManagement">
            <StatusBadge :tone="lifecycleTone">{{ asset.lifecycle_status_label }}</StatusBadge>
            <StatusBadge v-if="asset.condition_label" tone="neutral">
              {{ asset.condition_label }}
            </StatusBadge>
          </template>
          <StatusBadge v-else-if="asset.is_borrowable" tone="ok">Bisa dipinjam</StatusBadge>
          <StatusBadge v-else tone="warn">Sedang tidak bisa dipinjam</StatusBadge>
        </template>
      </PageHead>

      <div class="detail__layout">
        <span class="detail__rail" aria-hidden="true"><span class="detail__rail-fill"></span></span>

        <div class="detail__gallery">
          <p class="index-label">FOTO</p>

          <div class="gallery">
            <img
              v-if="mainPhoto"
              class="gallery__main"
              :src="mainPhoto.url"
              :alt="`Foto utama ${asset.name}`"
            />
            <span v-else class="gallery__empty" aria-hidden="true">Lendora</span>
            <div v-if="photos.length > 1" class="gallery__thumbs">
              <img
                v-for="photo in photos.slice(1)"
                :key="photo.id"
                class="gallery__thumb"
                :src="photo.url"
                :alt="`Foto ${asset.name}`"
              />
            </div>
          </div>
        </div>

        <aside class="detail__aside" aria-labelledby="ringkasan-title">
          <div class="summary panel panel--alt">
            <p class="index-label">RINGKASAN</p>
            <h2 id="ringkasan-title" class="summary__title">Data pencatatan.</h2>

            <!-- Kode aset sudah tampil di label PageHead; baris di sini hanya
                 data yang tidak muncul di kepala halaman. -->
            <dl class="summary__list">
              <div>
                <dt>Kategori</dt>
                <dd>{{ asset.category?.name ?? 'Tanpa kategori' }}</dd>
              </div>
              <div>
                <dt>Tarif sewa</dt>
                <dd>
                  <span v-if="asset.daily_rate != null" class="num">
                    {{ fmtPrice(asset.daily_rate) }} / hari
                  </span>
                  <template v-else>Belum ditetapkan</template>
                </dd>
              </div>
              <div v-if="asset.min_days">
                <dt>Minimal sewa</dt>
                <dd>{{ asset.min_days }} hari</dd>
              </div>
              <div>
                <dt>Lokasi</dt>
                <dd>{{ asset.location?.name ?? 'Tidak dicatat' }}</dd>
              </div>
              <template v-if="isManagement">
                <div>
                  <dt>Serial number</dt>
                  <dd>{{ asset.serial_number || 'Tidak ada' }}</dd>
                </div>
                <div>
                  <dt>Tanggal beli</dt>
                  <dd>{{ fmtDate(asset.purchase_date) || 'Tidak dicatat' }}</dd>
                </div>
                <div>
                  <dt>Harga beli</dt>
                  <dd>
                    <span v-if="asset.purchase_price != null" ref="priceEl" class="num">{{
                      fmtPrice(asset.purchase_price)
                    }}</span>
                    <template v-else>Tidak dicatat</template>
                  </dd>
                </div>
                <div>
                  <dt>Garansi sampai</dt>
                  <dd>{{ fmtDate(asset.warranty_until) || 'Tidak ada' }}</dd>
                </div>
              </template>
            </dl>

            <p v-if="isManagement && asset.notes" class="summary__notes">
              <span class="summary__notes-label">Catatan internal</span>
              {{ asset.notes }}
            </p>
          </div>
        </aside>

        <div class="detail__panels">
          <div v-if="editOpen" class="detail__form">
            <AssetForm
              :asset="asset"
              :categories="categories"
              :locations="locations"
              @saved="onSaved"
              @cancel="editOpen = false"
            />
          </div>

          <section class="panel" aria-labelledby="avail-title">
            <p class="index-label">KETERSEDIAAN</p>
            <h2 id="avail-title">Periksa ketersediaan</h2>
            <p class="text-2">
              Pilih periode, lalu periksa apakah aset ini bisa dipakai untuk periode itu. Hasil
              dihitung saat itu juga dari jadwal peminjaman.
            </p>

            <form class="avail-form" @submit.prevent="checkAvailability">
              <div class="field">
                <label for="av-start">Mulai</label>
                <input
                  id="av-start"
                  v-model="startsAt"
                  class="input"
                  type="datetime-local"
                  name="starts_at"
                />
              </div>
              <div class="field">
                <label for="av-end">Selesai</label>
                <input
                  id="av-end"
                  v-model="endsAt"
                  class="input"
                  type="datetime-local"
                  name="ends_at"
                />
              </div>
              <button
                type="submit"
                class="btn btn--primary"
                :disabled="checking || !startsAt || !endsAt"
              >
                {{ checking ? 'Memeriksa' : 'Periksa periode' }}
              </button>
            </form>

            <p v-if="checkError" class="form-error" role="alert">{{ checkError }}</p>

            <div v-if="availability" class="avail-result" role="status">
              <p class="avail-result__line">
                <StatusBadge :tone="availability.requestable ? 'ok' : 'err'">
                  {{
                    availability.requestable ? 'Periode bisa diminta' : 'Periode tidak bisa diminta'
                  }}
                </StatusBadge>
              </p>

              <ul v-if="availability.reasons?.length" class="avail-reasons">
                <li v-for="reason in availability.reasons" :key="reason">{{ reason }}</li>
              </ul>

              <ul v-if="availability.blocking?.length" class="avail-blocking">
                <li v-for="(period, index) in availability.blocking" :key="index">
                  {{ fmtDateTime(period.starts_at) }} sampai {{ fmtDateTime(period.ends_at) }}
                  <template v-if="period.borrower">, dipesan oleh {{ period.borrower }}</template>
                  <template v-else>
                    ({{ STATUS_LABELS[period.status] ?? period.status }})
                  </template>
                </li>
              </ul>

              <button
                v-if="!isManagement && availability.requestable"
                @click="requestLoan(id, startsAt, endsAt)"
                class="btn btn--primary avail-cta"
              >
                Minta pinjam periode ini
              </button>
            </div>

            <p v-if="!isManagement && !availability" class="avail-note">
              <button @click="requestLoan(id)" class="btn">Minta pinjam aset ini</button>
              <button @click="reportIssue(id)" class="btn">Laporkan masalah</button>
            </p>
          </section>

          <section v-if="isManagement" class="panel" aria-labelledby="act-title">
            <p class="index-label">AKSI</p>
            <h2 id="act-title">Aksi</h2>
            <div class="act-row">
              <button type="button" class="btn btn--primary" @click="editOpen = !editOpen">
                {{ editOpen ? 'Tutup perbaikan' : 'Perbaiki aset' }}
              </button>
              <button type="button" class="btn" @click="createTicket(id)">
                Buat tiket perawatan
              </button>

              <template v-if="isAdmin">
                <button
                  v-if="!isRetired && !retireOpen"
                  type="button"
                  class="btn"
                  @click="retireOpen = true"
                >
                  Hentikan pemakaian
                </button>
                <button type="button" class="btn" @click="removeAsset">Hapus aset</button>
              </template>
            </div>

            <form v-if="retireOpen" class="retire-form" @submit.prevent="submitRetire">
              <div class="field">
                <label for="retire-reason">Alasan penghentian</label>
                <textarea
                  id="retire-reason"
                  v-model="retireReason"
                  class="input"
                  rows="2"
                  required
                ></textarea>
              </div>
              <p v-if="retireError" class="form-error" role="alert">{{ retireError }}</p>
              <div class="act-row">
                <button type="submit" class="btn btn--primary" :disabled="retiring">
                  {{ retiring ? 'Menghentikan' : 'Hentikan pemakaian' }}
                </button>
                <button type="button" class="btn" @click="retireOpen = false">Batal</button>
              </div>
            </form>
          </section>
        </div>
      </div>
    </template>

    <ConfirmDialog
      v-if="removeOpen && asset"
      title="Hapus aset ini?"
      :message="`Aset '${asset.code}, ${asset.name}' dihapus permanen dari inventaris. Riwayat peminjaman yang sudah ada tetap tersimpan.`"
      confirm-label="Hapus aset"
      :busy="removing"
      :error="removeError"
      @confirm="confirmRemoveAsset"
      @cancel="removeOpen = false"
    />
  </section>
</template>

<style scoped>
.detail {
  display: grid;
  gap: 1.5rem;
}

.back {
  justify-self: start;
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  font-family: var(--ln-font-mono);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
  text-decoration: none;
}

.back:hover {
  color: var(--ln-text);
  text-decoration: underline;
}

/*
 * Kolom utama (galeri + panel) di kiri, ringkasan metadata sticky di kanan.
 * Urutan DOM galeri → ringkasan → panel dipertahankan agar di layar sempit
 * data aset tetap muncul tepat di bawah kepala halaman.
 */
.detail__layout {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1.75fr) minmax(0, 1fr);
  gap: 2rem;
  padding-left: 1.75rem;
  align-items: start;
}

/* Rail progres: garis tipis di tepi kiri yang terisi mengikuti gulir. */
.detail__rail {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 1px;
  background: var(--ln-border);
}

.detail__rail-fill {
  position: absolute;
  inset: 0;
  background: var(--ln-text);
  transform: scaleY(0);
  transform-origin: top center;
}

.detail__gallery {
  grid-column: 1;
  grid-row: 1;
  display: grid;
  gap: 0.75rem;
  align-content: start;
}

.detail__aside {
  grid-column: 2;
  grid-row: 1 / span 2;
  position: sticky;
  top: 1.5rem;
}

.detail__panels {
  grid-column: 1;
  grid-row: 2;
  display: grid;
  gap: 1.5rem;
}

.gallery {
  display: grid;
  gap: 0.5rem;
}

.gallery__main {
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
}

.gallery__empty {
  display: grid;
  place-items: center;
  aspect-ratio: 4 / 3;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
  color: var(--ln-text-2);
  font-family: var(--ln-font-display);
  font-weight: 700;
}

.gallery__thumbs {
  display: flex;
  gap: 0.5rem;
  overflow-x: auto;
}

.gallery__thumb {
  width: 4.5rem;
  height: 4.5rem;
  object-fit: cover;
  border: 1px solid var(--ln-border);
  flex-shrink: 0;
}

/* Ringkasan metadata: permukaan sekunder, baris bergaris. */
.summary {
  padding: 1.25rem;
}

.summary__title {
  margin-bottom: 1rem;
  font-size: var(--ln-title);
}

.summary__list {
  margin: 0;
  border-top: 1px solid var(--ln-border);
}

.summary__list > div {
  display: grid;
  gap: 0.25rem;
  min-height: 44px;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.summary__list dt {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.summary__list dd {
  margin: 0;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.summary__notes {
  margin: 1rem 0 0;
  font-size: 0.8125rem;
  line-height: 1.7;
  color: var(--ln-text-2);
}

.summary__notes-label {
  display: block;
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

.detail__panels .panel {
  padding: 1.5rem;
}

.avail-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
  gap: 0 1rem;
  align-items: end;
}

.avail-result {
  margin-top: 1rem;
  padding: 1rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
}

.avail-result__line {
  margin: 0 0 0.5rem;
}

.avail-reasons,
.avail-blocking {
  margin: 0.5rem 0 0;
  padding-left: 1.25rem;
}

.avail-note {
  margin: 1rem 0 0;
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.avail-cta {
  margin-top: 0.75rem;
}

.act-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.retire-form {
  margin-top: 1rem;
  max-width: 36rem;
  padding: 1rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
}

@media (max-width: 64rem) {
  .detail__layout {
    grid-template-columns: 1fr;
  }

  .detail__gallery,
  .detail__aside,
  .detail__panels {
    grid-column: 1;
    grid-row: auto;
  }

  .detail__aside {
    position: static;
  }
}

@media (max-width: 48rem) {
  .detail__layout {
    padding-left: 1.25rem;
  }

  .avail-form {
    grid-template-columns: 1fr;
  }
}
</style>
