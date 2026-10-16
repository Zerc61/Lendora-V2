<script setup>
/*
 * Detail penyedia (publik, tanpa masuk): profil, kontak, dan daftar peralatan
 * dari GET /public/providers/{slug} dan .../equipment.
 *
 * Periode diperiksa sekali di panel atas, lalu dipakai untuk memeriksa
 * peralatan mana pun. Hasil per peralatan datang dari
 * GET /public/providers/{slug}/equipment/{id}/availability dan hanya berisi
 * bisa/tidak plus alasannya, tanpa identitas peminjam.
 *
 * Mengajukan sewa memerlukan akun, tetapi tidak memerlukan membership: rutenya
 * POST /providers/{slug}/bookings, di luar konteks tenant. Totalnya baru
 * diketahui setelah server menghitung, jadi yang ditampilkan sebelum mengajukan
 * adalah tarif harian apa adanya, bukan hasil hitung di peramban.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime, fmtPrice } from '../lib/format'
import { auth } from '../lib/auth'
import { usePageMotion } from '../lib/motion'
import PublicSiteHeader from '../components/PublicSiteHeader.vue'
import PageHead from '../components/ui/PageHead.vue'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

const route = useRoute()
const router = useRouter()
const slug = computed(() => route.params.slug)

const provider = ref(null)
const equipment = ref([])
const equipmentMeta = ref(null)
const loading = ref(true)
const error = ref(null)
const equipmentError = ref(null)
const notFound = ref(false)

const startsAt = ref('')
const endsAt = ref('')
const results = ref({})
const checkingId = ref(null)
const checkError = ref(null)

const bookingId = ref(null)
const bookingResults = ref({})
const bookingErrors = ref({})

const page = ref(null)
const motion = usePageMotion(page)

function pad(value) {
  return String(value).padStart(2, '0')
}

function localValue(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours(),
  )}:${pad(date.getMinutes())}`
}

/* Nilai awal: besok 09:00 sampai 13:00, supaya panel bisa langsung dipakai. */
function defaultPeriod() {
  const start = new Date()
  start.setDate(start.getDate() + 1)
  start.setHours(9, 0, 0, 0)

  const end = new Date(start)
  end.setHours(13, 0, 0, 0)

  startsAt.value = localValue(start)
  endsAt.value = localValue(end)
}

const periodLabel = computed(() => {
  if (!startsAt.value || !endsAt.value) {
    return null
  }
  return `${fmtDateTime(startsAt.value)} sampai ${fmtDateTime(endsAt.value)}`
})

const contacts = computed(() => {
  if (!provider.value) {
    return []
  }
  return [
    { label: 'Telepon', value: provider.value.contact_phone },
    { label: 'Surel', value: provider.value.contact_email },
    { label: 'Alamat', value: provider.value.address },
    { label: 'Kota', value: provider.value.city },
  ].filter((row) => row.value)
})

async function loadProvider() {
  loading.value = true
  error.value = null
  notFound.value = false

  try {
    const payload = await apiGet(`/public/providers/${encodeURIComponent(slug.value)}`)
    provider.value = payload.data ?? null
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) {
      notFound.value = true
    } else {
      error.value = e instanceof ApiError ? e.message : 'Gagal memuat profil penyedia.'
    }
  } finally {
    loading.value = false
  }
}

async function loadEquipment(pageNumber = 1) {
  const params = new URLSearchParams({ page: String(pageNumber) })
  equipmentError.value = null

  try {
    const payload = await apiGet(
      `/public/providers/${encodeURIComponent(slug.value)}/equipment?${params.toString()}`,
    )
    equipment.value = payload.data ?? []
    equipmentMeta.value = payload.meta ?? null
  } catch (e) {
    equipment.value = []
    equipmentError.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar peralatan.'
  }
}

const equipmentUrl = (assetId) => {
  const params = new URLSearchParams({ starts_at: startsAt.value, ends_at: endsAt.value })
  return `/public/providers/${encodeURIComponent(slug.value)}/equipment/${assetId}/availability?${params.toString()}`
}

async function checkAvailability(asset) {
  checkingId.value = asset.id
  checkError.value = null

  try {
    const payload = await apiGet(equipmentUrl(asset.id))
    results.value = { ...results.value, [asset.id]: payload.data ?? null }
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      checkError.value = 'Periode tidak valid. Pastikan waktu selesai setelah waktu mulai.'
    } else if (e instanceof ApiError && e.status === 429) {
      checkError.value =
        'Terlalu banyak permintaan dari jaringan ini. Tunggu sebentar lalu coba lagi.'
    } else {
      checkError.value = e instanceof ApiError ? e.message : 'Gagal memeriksa ketersediaan.'
    }
  } finally {
    checkingId.value = null
  }
}

async function loadAll(pageNumber = 1) {
  results.value = {}
  bookingResults.value = {}
  bookingErrors.value = {}
  await Promise.all([loadProvider(), loadEquipment(pageNumber)])
  await nextTick()
  motion.reveal('.equipment__item', { y: 12, stagger: 0.04 })
  motion.refresh()
}

/* Pesan pertama dari validation error Laravel, untuk ditampilkan apa adanya. */
function firstError(errors) {
  for (const key of Object.keys(errors ?? {})) {
    if (errors[key]?.[0]) {
      return errors[key][0]
    }
  }

  return null
}

/* Galat pengajuan ditampilkan pada baris peralatan yang diklik, bukan di atas. */
function setBookingError(assetId, message) {
  bookingErrors.value = { ...bookingErrors.value, [assetId]: message }
}

async function book(asset) {
  if (!auth.user) {
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }

  bookingId.value = asset.id
  setBookingError(asset.id, null)

  try {
    const payload = await apiPost(`/providers/${encodeURIComponent(slug.value)}/bookings`, {
      starts_at: startsAt.value,
      ends_at: endsAt.value,
      assets: [asset.id],
    })
    bookingResults.value = { ...bookingResults.value, [asset.id]: payload.data ?? null }
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      router.push({ name: 'login', query: { redirect: route.fullPath } })
      return
    }

    if (e instanceof ApiError && e.status === 422) {
      setBookingError(
        asset.id,
        firstError(e.errors) ?? 'Periode atau peralatan ini belum bisa dipesan.',
      )
    } else if (e instanceof ApiError && e.status === 429) {
      setBookingError(
        asset.id,
        'Terlalu banyak permintaan dari jaringan ini. Tunggu sebentar lalu coba lagi.',
      )
    } else {
      setBookingError(asset.id, e instanceof ApiError ? e.message : 'Gagal mengajukan sewa.')
    }
  } finally {
    bookingId.value = null
  }
}

function goToPage(pageNumber) {
  loadEquipment(pageNumber).then(() => {
    motion.refresh()
  })
}

onMounted(() => {
  defaultPeriod()
  loadAll()
})

watch(slug, () => {
  defaultPeriod()
  loadAll()
})
</script>

<template>
  <div ref="page" class="provider">
    <PublicSiteHeader />

    <main class="container provider__body">
      <LoadingState v-if="loading" label="Memuat profil penyedia" />

      <EmptyState
        v-else-if="notFound"
        title="Penyedia tidak ditemukan"
        hint="Penyedia ini tidak aktif, belum diverifikasi, atau tautannya salah."
      >
        <template #action>
          <RouterLink :to="{ name: 'explore' }" class="btn">Lihat penyedia lain</RouterLink>
        </template>
      </EmptyState>

      <ErrorState
        v-else-if="error"
        title="Profil penyedia gagal dimuat"
        :message="error"
        @retry="loadAll()"
      />

      <template v-else-if="provider">
        <RouterLink :to="{ name: 'explore' }" class="provider__back index-label">
          Kembali ke daftar penyedia
        </RouterLink>

        <PageHead label="PENYEDIA" :title="provider.name" :lede="provider.description" />

        <dl v-if="contacts.length" class="contacts">
          <div v-for="row in contacts" :key="row.label" class="contacts__row">
            <dt class="index-label">{{ row.label }}</dt>
            <dd class="contacts__value">{{ row.value }}</dd>
          </div>
        </dl>

        <section class="panel panel--alt period" aria-labelledby="period-title">
          <p class="index-label">PERIODE</p>
          <h2 id="period-title" class="period__title">Kapan kamu butuh peralatan ini?</h2>
          <p class="period__copy text-2">
            Isi periode sekali, lalu periksa peralatan mana pun. Hasil dihitung saat itu juga dari
            jadwal pemesanan yang sudah ada.
          </p>

          <form class="period__form" @submit.prevent>
            <div class="field">
              <label for="period-start">Mulai</label>
              <input id="period-start" v-model="startsAt" class="input" type="datetime-local" />
            </div>
            <div class="field">
              <label for="period-end">Selesai</label>
              <input id="period-end" v-model="endsAt" class="input" type="datetime-local" />
            </div>
          </form>

          <p v-if="checkError" class="form-error" role="alert">{{ checkError }}</p>
        </section>

        <section class="equipment" aria-labelledby="equipment-title">
          <div class="rule-top equipment__head">
            <p class="index-label">PERALATAN</p>
            <h2 id="equipment-title" class="equipment__title">Daftar peralatan</h2>
          </div>

          <ul v-if="equipment.length" class="equipment__list">
            <li v-for="asset in equipment" :key="asset.id" class="equipment__item">
              <div class="equipment__main">
                <h3 class="equipment__name">{{ asset.name }}</h3>
                <p class="equipment__rate">
                  <template v-if="asset.daily_rate != null">
                    <span class="equipment__amount">{{ fmtPrice(asset.daily_rate) }}</span>
                    <span class="equipment__unit">per hari</span>
                  </template>
                  <span v-else class="equipment__norate">Harga belum ditetapkan.</span>
                </p>
                <p v-if="asset.min_days" class="equipment__min">
                  Minimal {{ asset.min_days }} hari. Periode lebih pendek tetap dihitung
                  {{ asset.min_days }} hari.
                </p>
                <p v-if="asset.category?.name" class="index-label">{{ asset.category.name }}</p>
                <p v-if="asset.description" class="equipment__desc">{{ asset.description }}</p>
              </div>

              <div class="equipment__check">
                <button
                  type="button"
                  class="btn"
                  :disabled="!startsAt || !endsAt || checkingId === asset.id"
                  @click="checkAvailability(asset)"
                >
                  {{ checkingId === asset.id ? 'Memeriksa' : 'Periksa ketersediaan' }}
                </button>

                <div v-if="results[asset.id]" class="equipment__result" role="status">
                  <p class="equipment__badge">
                    <StatusBadge :tone="results[asset.id].requestable ? 'ok' : 'err'">
                      {{
                        results[asset.id].requestable
                          ? 'Bisa dipinjam untuk periode itu'
                          : 'Tidak bisa untuk periode itu'
                      }}
                    </StatusBadge>
                  </p>
                  <p v-if="periodLabel" class="equipment__period">{{ periodLabel }}</p>
                  <p v-if="results[asset.id].assets?.[0]?.reason" class="equipment__reason">
                    {{ results[asset.id].assets[0].reason }}
                  </p>
                  <ul v-if="results[asset.id].reasons?.length" class="equipment__reasons">
                    <li v-for="reason in results[asset.id].reasons" :key="reason">{{ reason }}</li>
                  </ul>
                </div>

                <p
                  v-if="results[asset.id]?.requestable && asset.daily_rate == null"
                  class="equipment__reason"
                >
                  Penyedia belum menetapkan tarif, jadi pengajuan belum dibuka.
                </p>

                <div
                  v-if="results[asset.id]?.requestable && asset.daily_rate != null"
                  class="equipment__book"
                >
                  <div v-if="bookingResults[asset.id]" class="equipment__booked" role="status">
                    <p class="equipment__badge">
                      <StatusBadge
                        :tone="bookingResults[asset.id].status === 'approved' ? 'ok' : 'info'"
                      >
                        {{ bookingResults[asset.id].status_label ?? 'Terkirim' }}
                      </StatusBadge>
                    </p>
                    <p class="equipment__period">
                      {{ fmtDateTime(bookingResults[asset.id].starts_at) }} sampai
                      {{ fmtDateTime(bookingResults[asset.id].ends_at) }}
                    </p>
                    <p class="equipment__total">
                      Total
                      {{ fmtPrice(bookingResults[asset.id].subtotal) ?? 'dihitung penyedia' }}
                    </p>
                    <RouterLink :to="{ name: 'bookings' }" class="btn">
                      Lihat booking saya
                    </RouterLink>
                  </div>

                  <RouterLink
                    v-else-if="!auth.user"
                    :to="{ name: 'login', query: { redirect: route.fullPath } }"
                    class="btn btn--primary"
                  >
                    Masuk untuk mengajukan sewa
                  </RouterLink>

                  <button
                    v-else
                    type="button"
                    class="btn btn--primary"
                    :disabled="bookingId === asset.id"
                    @click="book(asset)"
                  >
                    {{ bookingId === asset.id ? 'Mengajukan' : 'Ajukan sewa' }}
                  </button>
                </div>

                <p v-if="bookingErrors[asset.id]" class="form-error" role="alert">
                  {{ bookingErrors[asset.id] }}
                </p>
              </div>
            </li>
          </ul>

          <ErrorState
            v-else-if="equipmentError"
            title="Daftar peralatan gagal dimuat"
            :message="equipmentError"
            @retry="goToPage(equipmentMeta?.current_page ?? 1)"
          />

          <EmptyState
            v-else
            title="Penyedia ini belum punya peralatan yang tampil"
            hint="Belum ada peralatan aktif dari penyedia ini. Coba penyedia lain."
          >
            <template #action>
              <RouterLink :to="{ name: 'explore' }" class="btn">Lihat penyedia lain</RouterLink>
            </template>
          </EmptyState>

          <Pagination
            v-if="equipmentMeta && equipmentMeta.last_page > 1"
            :current="equipmentMeta.current_page"
            :last="equipmentMeta.last_page"
            :total="equipmentMeta.total"
            :from="equipmentMeta.from"
            :to="equipmentMeta.to"
            @page="goToPage"
          />
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.provider {
  min-height: 100vh;
  background: var(--ln-bg);
}

.provider__body {
  display: grid;
  gap: 2rem;
  padding-block: var(--ln-section-y);
}

.provider__back {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  width: fit-content;
  color: var(--ln-text-2);
  text-decoration: none;
}

.provider__back:hover {
  color: var(--ln-text);
}

.contacts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 1.25rem;
  margin: 0;
  padding-block: 1.5rem;
  border-top: 1px solid var(--ln-border);
  border-bottom: 1px solid var(--ln-border);
}

.contacts__row {
  display: grid;
  gap: 0.25rem;
}

.contacts__row .index-label {
  margin: 0;
}

.contacts__value {
  margin: 0;
  max-width: 32ch;
}

.period {
  display: grid;
  gap: 0.5rem;
  padding: 1.5rem;
}

.period__title {
  margin: 0;
  font-size: var(--ln-title);
}

.period__copy {
  max-width: 56ch;
  margin: 0;
}

.period__form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 1rem;
  margin-top: 0.75rem;
}

.period__form .field {
  margin: 0;
}

.equipment {
  display: grid;
  gap: 1rem;
}

.equipment__head {
  display: grid;
  gap: 0.25rem;
}

.equipment__head .index-label {
  margin: 0;
}

.equipment__title {
  margin: 0;
  font-size: var(--ln-display-sm);
  letter-spacing: -0.045em;
}

.equipment__list {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.equipment__item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 1.5rem;
  align-items: start;
  padding-block: 1.5rem;
  border-bottom: 1px solid var(--ln-border);
}

.equipment__main {
  display: grid;
  gap: 0.375rem;
  min-width: 0;
}

.equipment__main .index-label {
  margin: 0;
}

.equipment__name {
  margin: 0;
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
}

.equipment__desc {
  max-width: 60ch;
  margin: 0;
  color: var(--ln-text-2);
  line-height: 1.65;
}

.equipment__rate {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem;
  margin: 0;
}

.equipment__amount {
  font-family: var(--ln-font-display);
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
}

.equipment__unit {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.equipment__norate,
.equipment__min {
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}

.equipment__min {
  margin: 0;
}

.equipment__book {
  display: grid;
  gap: 0.75rem;
  justify-items: start;
}

.equipment__booked {
  display: grid;
  gap: 0.375rem;
  justify-items: start;
  padding: 0.75rem;
  border: 1px solid var(--ln-border-strong);
  background: var(--ln-surface);
  text-align: left;
}

.equipment__total {
  margin: 0;
  font-family: var(--ln-font-display);
  font-size: var(--ln-title);
  letter-spacing: -0.02em;
}

.equipment__check {
  display: grid;
  gap: 0.75rem;
  justify-items: start;
  min-width: 15rem;
}

.equipment__result {
  display: grid;
  gap: 0.375rem;
  justify-items: start;
  padding: 0.75rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
  text-align: left;
}

.equipment__badge,
.equipment__period,
.equipment__reason {
  margin: 0;
}

.equipment__period {
  font-family: var(--ln-font-mono);
  font-size: 0.75rem;
  letter-spacing: 0.04em;
  color: var(--ln-text-2);
}

.equipment__reason {
  max-width: 34ch;
  color: var(--ln-text-2);
}

.equipment__reasons {
  margin: 0;
  padding-inline-start: 1.1rem;
  color: var(--ln-text-2);
}

@media (max-width: 60rem) {
  .equipment__item {
    grid-template-columns: minmax(0, 1fr);
  }

  .equipment__check {
    min-width: 0;
  }
}
</style>
