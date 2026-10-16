<script setup>
/*
 * Dompet: saldo, isi saldo lewat Midtrans, dan riwayat transaksi.
 *
 * Saldo tidak dihitung di klien. Angka datang dari /wallet/balance, dan saldo
 * baru bertambah setelah server memastikan pembayarannya ke Midtrans. Halaman
 * ini memicu pemeriksaan itu sendiri (`/wallet/topups/sync`) saat dibuka dan
 * setelah halaman pembayaran ditutup, jadi saldo tetap masuk walau notifikasi
 * Midtrans tidak bisa menjangkau server (mis. dev lokal tanpa tunnel) — tanpa
 * perlu mengatur URL notifikasi di dashboard Midtrans.
 */
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime, fmtPrice, WALLET_TONES } from '../lib/format'
import {
  signedWalletAmount,
  topUpError as topUpErrorMessage,
  topUpLimits,
  walletExpiry,
} from '../lib/wallet'
import { payWithSnap } from '../lib/snap'
import { usePageMotion } from '../lib/motion'
import PageHead from '../components/ui/PageHead.vue'
import StatusBadge from '../components/StatusBadge.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import Pagination from '../components/Pagination.vue'

/*
 * Pilihan nominal cepat. Batas nominal tetap dari server (`/wallet/balance`),
 * jadi daftar ini hanya jalan pintas — semuanya masih di dalam batas.
 */
const PRESETS = [
  10000, 20000, 25000, 50000, 75000, 100000, 150000, 200000, 250000, 300000, 400000, 500000, 600000,
  750000, 900000, 1000000,
]

/*
 * Selang pemeriksaan status saat menunggu pembayaran, dan batas jumlah
 * percobaan sebelum menyerahkan ke tombol "Cek status".
 */
const POLL_INTERVAL = 3000
const POLL_LIMIT = 40

const route = useRoute()
const router = useRouter()

/*
 * Midtrans mengembalikan pengguna ke halaman ini dengan `order_id` di query,
 * jadi pengguna yang baru membayar lewat jalur redirect bisa langsung masuk ke
 * tahap menunggu tanpa menekan apa pun.
 */
const returnOrderId = typeof route.query.order_id === 'string' ? route.query.order_id : null

const page = ref(null)
const motion = usePageMotion(page)

const balance = ref(null)
const balanceError = ref(null)
const transactions = ref([])
const meta = ref(null)
const currentPage = ref(1)
const loading = ref(false)
const error = ref(null)
const topupAmount = ref(50000)
const topupBusy = ref(false)
const topupError = ref(null)
const settleNotice = ref(null)
const limits = ref(topUpLimits(null))

// Tahap alur top up: `form` (nominal) -> `waiting` (menunggu pembayaran) ->
// `success` (pembayaran diterima).
const stage = ref('form')
const paidTotal = ref(0)
const pollTimedOut = ref(false)
let pollTimer = null

async function loadBalance() {
  try {
    const payload = await apiGet('/wallet/balance')
    balance.value = payload.data.wallet_balance
    limits.value = topUpLimits(payload.data)
    balanceError.value = null
  } catch (e) {
    balanceError.value = e instanceof ApiError ? e.message : 'Gagal memuat saldo.'
  }
}

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null
  currentPage.value = pageNumber

  try {
    const payload = await apiGet(`/wallet/transactions?page=${pageNumber}`)
    transactions.value = payload.data ?? []
    meta.value = {
      current_page: payload.current_page,
      last_page: payload.last_page,
      total: payload.total,
      from: payload.from,
      to: payload.to,
    }
    await motion.reveal('.wallet__row', { y: 12, stagger: 0.03 })
  } catch (e) {
    transactions.value = []
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat transaksi.'
  } finally {
    loading.value = false
  }
}

async function refresh() {
  await Promise.all([loadBalance(), load(currentPage.value)])
}

/*
 * Tanya server status top up yang masih menunggu. Server yang menanyakan ke
 * Midtrans; klien tidak pernah menambah saldo sendiri. Balikannya jumlah dan
 * total nominal yang baru lunas.
 */
async function syncPendingTopUps() {
  const waiting = transactions.value.some(
    (transaction) => transaction.type === 'topup' && transaction.status === 'pending',
  )

  if (!waiting) {
    return { count: 0, amount: 0 }
  }

  try {
    const payload = await apiPost('/wallet/topups/sync', {})
    const settled = payload?.data?.settled ?? []

    return { count: settled.length, amount: Number(payload?.data?.settled_amount ?? 0) }
  } catch {
    // Gagal bertanya ke Midtrans bukan kegagalan halaman: tombol "Cek status"
    // tetap ada, dan rekonsiliasi terjadwal menyusul.
    return { count: 0, amount: 0 }
  }
}

function stopPolling() {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

/*
 * Tahap menunggu: status dicek berkala sampai Midtrans melaporkan pembayaran,
 * lalu halaman pindah ke tahap berhasil. Batas percobaan menjaga halaman tidak
 * menanyakan selamanya; setelah itu pengguna memakai tombol "Cek status".
 */
function startPolling(attempt = 0) {
  stopPolling()
  pollTimedOut.value = false

  pollTimer = setTimeout(
    async () => {
      pollTimer = null

      const { count, amount } = await syncPendingTopUps()

      if (count > 0) {
        await markPaid(amount)
        return
      }

      if (attempt + 1 >= POLL_LIMIT) {
        pollTimedOut.value = true
        return
      }

      startPolling(attempt + 1)
    },
    attempt === 0 ? 0 : POLL_INTERVAL,
  )
}

async function markPaid(amount) {
  stopPolling()
  paidTotal.value = amount
  await refresh()
  stage.value = 'success'

  // Query `order_id` dibersihkan supaya muat ulang halaman tidak memulai
  // penantian untuk pembayaran yang sudah selesai.
  if (returnOrderId && route.query.order_id) {
    await router.replace({ query: {} })
  }
}

/*
 * Tombol "Cek status": sekali tanya sekarang, lalu lanjut menunggu berkala.
 */
async function checkStatusNow() {
  topupBusy.value = true
  const { count, amount } = await syncPendingTopUps()
  topupBusy.value = false

  if (count > 0) {
    await markPaid(amount)
    return
  }

  startPolling(1)
}

function resetTopup() {
  stopPolling()
  stage.value = 'form'
  topupAmount.value = 50000
  paidTotal.value = 0
  topupError.value = null
  pollTimedOut.value = false
}

function gotoHistory() {
  document.querySelector('.wallet__history')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function topup() {
  const message = topUpErrorMessage(topupAmount.value, limits.value)

  if (message) {
    topupError.value = message
    return
  }

  topupBusy.value = true
  topupError.value = null
  settleNotice.value = null

  try {
    const payload = await apiPost('/wallet/topup', { amount: topupAmount.value })
    const data = payload?.data ?? {}

    if (!data.snap_token && !data.redirect_url) {
      topupError.value = 'Tautan pembayaran tidak tersedia. Coba lagi.'
      return
    }

    // Masuk tahap menunggu sebelum halaman pembayaran terbuka: Snap membuka
    // jendelanya di atas halaman ini, dan status dicek berkala begitu hasilnya
    // dilaporkan.
    stage.value = 'waiting'
    startPolling()

    const opened = await payWithSnap({
      token: data.snap_token,
      clientKey: data.snap_client_key,
      isProduction: data.snap_is_production === true,
      onSettled: () => startPolling(),
    })

    if (opened) {
      return
    }

    // Snap tidak bisa dimuat: pembayaran lanjut di halaman Midtrans, dan
    // pengguna kembali ke sini dengan `order_id` di query.
    window.location.assign(data.redirect_url)
  } catch (e) {
    stage.value = 'form'
    stopPolling()
    topupError.value = e instanceof ApiError ? e.message : 'Gagal memulai pembayaran.'
  } finally {
    topupBusy.value = false
  }
}

onMounted(async () => {
  await refresh()

  // Kembali dari halaman pembayaran Midtrans: langsung tunggu pembayarannya.
  if (
    returnOrderId &&
    transactions.value.some(
      (transaction) =>
        transaction.reference_id === returnOrderId && transaction.status === 'pending',
    )
  ) {
    stage.value = 'waiting'
    startPolling()
    return
  }

  // Dibuka biasa: kalau ada top up yang menunggu, tanya sekali ke Midtrans.
  const { count, amount } = await syncPendingTopUps()

  if (count > 0) {
    settleNotice.value = `Pembayaran diterima: ${fmtPrice(amount)} ditambahkan ke saldo.`
    await refresh()
  }
})

onUnmounted(stopPolling)
</script>

<template>
  <section ref="page" class="wallet">
    <PageHead
      label="DOMPET"
      title="Dompet"
      lede="Saldo akunmu untuk membayar sewa di Lendora. Isi saldo lewat Midtrans, lalu pakai saat membayar booking."
    />

    <div class="wallet__grid">
      <div class="panel wallet__card">
        <p class="index-label">SALDO TERSEDIA</p>
        <p class="wallet__amount num">
          <template v-if="balance === null">—</template>
          <template v-else>{{ fmtPrice(balance) }}</template>
        </p>
        <p class="wallet__note">
          Saldo hanya bisa dipakai untuk sewa di Lendora. Penarikan ke rekening dan transfer ke akun
          lain belum tersedia.
        </p>
        <p v-if="settleNotice" class="wallet__notice" role="status">{{ settleNotice }}</p>
        <p v-if="balanceError" class="field-error" role="alert">{{ balanceError }}</p>
      </div>

      <div class="panel wallet__card">
        <!-- Tahap 1 — nominal -->
        <template v-if="stage === 'form'">
          <p class="index-label">ISI SALDO</p>

          <form class="wallet__form" @submit.prevent="topup">
            <div class="field">
              <label for="wallet-amount">Nominal</label>
              <input
                id="wallet-amount"
                v-model.number="topupAmount"
                class="input num"
                type="number"
                inputmode="numeric"
                :min="limits.min"
                :max="limits.max"
                step="1000"
                required
              />
              <p class="hint">
                Minimal {{ fmtPrice(limits.min) }}, maksimal {{ fmtPrice(limits.max) }} per
                transaksi.
              </p>
            </div>

            <ul class="wallet__presets">
              <li v-for="preset in PRESETS" :key="preset">
                <button
                  type="button"
                  class="wallet__preset num"
                  :class="{ 'wallet__preset--on': topupAmount === preset }"
                  :aria-pressed="topupAmount === preset"
                  @click="topupAmount = preset"
                >
                  {{ fmtPrice(preset) }}
                </button>
              </li>
            </ul>

            <p v-if="topupError" class="form-error" role="alert">{{ topupError }}</p>

            <button type="submit" class="btn btn--primary" :disabled="topupBusy">
              {{ topupBusy ? 'Menyiapkan pembayaran…' : 'Lanjut ke pembayaran' }}
            </button>

            <p class="wallet__note">
              Pembayaran diproses Midtrans. Saldo bertambah setelah Midtrans mengonfirmasi ke server
              — halaman ini yang menanyakannya, jadi tidak ada notifikasi yang perlu diatur.
            </p>
          </form>
        </template>

        <!-- Tahap 2 — menunggu pembayaran -->
        <template v-else-if="stage === 'waiting'">
          <p class="index-label">MENUNGGU PEMBAYARAN</p>

          <p class="wallet__waiting-amount num">{{ fmtPrice(topupAmount) }}</p>

          <p class="wallet__note" role="status" aria-live="polite">
            <template v-if="pollTimedOut">
              Belum ada konfirmasi dari Midtrans. Kalau kamu sudah membayar, tekan Cek status.
            </template>
            <template v-else>
              Selesaikan pembayaran di jendela Midtrans. Halaman ini menunggu konfirmasi…
            </template>
          </p>

          <div class="wallet__waiting-actions">
            <button
              type="button"
              class="btn btn--ghost wallet__check"
              :disabled="topupBusy"
              @click="checkStatusNow"
            >
              {{ topupBusy ? 'Memeriksa…' : 'Cek status' }}
            </button>
            <button type="button" class="wallet__little" @click="resetTopup">Ganti nominal</button>
          </div>

          <p class="wallet__note wallet__note--tiny">
            Status dicek otomatis tiap beberapa detik. Saldo tidak pernah ditambah dari browser —
            server yang memastikan pembayarannya ke Midtrans.
          </p>
        </template>

        <!-- Tahap 3 — berhasil -->
        <template v-else>
          <div class="wallet__paid">
            <svg
              class="wallet__check-mark"
              viewBox="0 0 72 72"
              role="img"
              aria-label="Pembayaran berhasil"
            >
              <circle class="wallet__check-ring" cx="36" cy="36" r="32" />
              <path class="wallet__check-tick" d="M22 37.5 L32 47 L50 27" />
            </svg>

            <p class="wallet__paid-title" role="status" aria-live="polite">Pembayaran berhasil</p>
            <p class="wallet__paid-amount num">+{{ fmtPrice(paidTotal) }}</p>
            <p class="wallet__paid-balance">
              Saldo sekarang
              <span class="num">{{ balance === null ? '—' : fmtPrice(balance) }}</span>
            </p>
          </div>

          <button type="button" class="btn btn--primary" @click="resetTopup">Isi saldo lagi</button>
          <button type="button" class="btn btn--ghost" @click="gotoHistory">Lihat riwayat</button>
        </template>
      </div>
    </div>

    <section class="wallet__history">
      <div class="wallet__head">
        <h2 class="index-label wallet__heading">RIWAYAT TRANSAKSI</h2>
        <button type="button" class="btn btn--ghost" :disabled="loading" @click="refresh">
          Muat ulang
        </button>
      </div>

      <LoadingState v-if="loading" label="Memuat transaksi" />

      <ErrorState
        v-else-if="error"
        title="Riwayat gagal dimuat"
        :message="error"
        @retry="load(1)"
      />

      <EmptyState
        v-else-if="!transactions.length"
        title="Belum ada transaksi"
        hint="Isi saldo untuk mulai. Setiap top up dan pembayaran sewa tercatat di sini."
      />

      <template v-else>
        <ul class="wallet__rows">
          <li v-for="transaction in transactions" :key="transaction.id" class="wallet__row">
            <div class="wallet__row-main">
              <p class="wallet__row-type">{{ transaction.type_label }}</p>
              <p class="wallet__row-ref num">{{ transaction.reference_id ?? '—' }}</p>
              <p v-if="walletExpiry(transaction)" class="wallet__row-expiry text-2">
                Bayar sebelum {{ fmtDateTime(walletExpiry(transaction)) }}
              </p>
            </div>

            <p class="wallet__row-date text-2">{{ fmtDateTime(transaction.created_at) }}</p>

            <p
              class="wallet__row-amount num"
              :class="`wallet__row-amount--${transaction.direction}`"
            >
              {{ signedWalletAmount(transaction) }}
            </p>

            <StatusBadge :tone="WALLET_TONES[transaction.status] ?? 'neutral'">
              {{ transaction.status_label }}
            </StatusBadge>
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
    </section>
  </section>
</template>

<style scoped>
.wallet {
  display: grid;
  gap: 2rem;
}

.wallet__grid {
  display: grid;
  gap: 1rem;
}

.wallet__card {
  display: grid;
  gap: 0.75rem;
  align-content: start;
}

.wallet__card .index-label {
  margin: 0;
}

.wallet__amount {
  margin: 0;
  font-size: var(--ln-display-sm);
  font-weight: 500;
  letter-spacing: -0.03em;
}

.wallet__note {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
  line-height: 1.65;
}

.wallet__note--tiny {
  font-size: 0.75rem;
  line-height: 1.6;
}

.wallet__waiting-amount {
  margin: 0;
  font-size: var(--ln-display-sm);
  font-weight: 500;
  letter-spacing: -0.03em;
}

.wallet__waiting-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/*
 * "Cek status" sengaja kecil: aksi sekunder, bukan tombol utama tahap ini.
 */
.wallet__check {
  min-height: 40px;
  padding-inline: 0.9rem;
  font-size: 0.8125rem;
}

.wallet__little {
  min-height: 40px;
  padding: 0 0.25rem;
  border: 0;
  background: none;
  color: var(--ln-text-2);
  font: inherit;
  font-size: 0.75rem;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.wallet__little:hover {
  color: var(--ln-text);
}

.wallet__paid {
  display: grid;
  justify-items: center;
  gap: 0.35rem;
  padding: 0.75rem 0 0.25rem;
  text-align: center;
}

.wallet__check-mark {
  width: 72px;
  height: 72px;
  overflow: visible;
}

.wallet__check-ring {
  fill: var(--ln-ok-bg);
  stroke: var(--ln-ok);
  stroke-width: 3;
  stroke-dasharray: 202;
  stroke-dashoffset: 202;
  animation: wallet-draw 520ms var(--ln-ease) forwards;
}

.wallet__check-tick {
  fill: none;
  stroke: var(--ln-ok);
  stroke-width: 5;
  stroke-linecap: square;
  stroke-dasharray: 44;
  stroke-dashoffset: 44;
  animation: wallet-draw 300ms 380ms var(--ln-ease) forwards;
}

.wallet__paid-title {
  margin: 0.35rem 0 0;
  font-size: 1rem;
  font-weight: 600;
}

.wallet__paid-amount {
  margin: 0;
  color: var(--ln-ok);
  font-size: var(--ln-display-sm);
  font-weight: 500;
  letter-spacing: -0.03em;
}

.wallet__paid-balance {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}

@keyframes wallet-draw {
  to {
    stroke-dashoffset: 0;
  }
}

/* Tanpa animasi: tanda centang langsung tampil penuh. */
@media (prefers-reduced-motion: reduce) {
  .wallet__check-ring,
  .wallet__check-tick {
    animation: none;
    stroke-dashoffset: 0;
  }
}

.wallet__form {
  display: grid;
  gap: 0.75rem;
}

.wallet__presets {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.wallet__preset {
  width: 100%;
  min-height: 40px;
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
  color: var(--ln-text-2);
  font: inherit;
  font-size: 0.8125rem;
  cursor: pointer;
  transition:
    color var(--ln-motion-fast) var(--ln-ease),
    border-color var(--ln-motion-fast) var(--ln-ease);
}

.wallet__preset:hover {
  border-color: var(--ln-border-strong);
  color: var(--ln-text);
}

.wallet__preset--on {
  border-color: var(--ln-border-strong);
  background: var(--ln-surface-alt);
  color: var(--ln-text);
}

.wallet__notice {
  margin: 0.75rem 0 0;
  padding: 0.5rem 0.75rem;
  background: var(--ln-ok-bg);
  color: var(--ln-ok);
  font-size: 0.82rem;
}

.wallet__history {
  display: grid;
  gap: 1rem;
}
.wallet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.wallet__heading {
  margin: 0;
}

.wallet__rows {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

/* Baris editorial: dua kolom di ponsel, empat kolom di layar lebar. */
.wallet__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.25rem 1rem;
  padding-block: 0.875rem;
  border-bottom: 1px solid var(--ln-border);
}

.wallet__row-type {
  margin: 0;
  font-weight: 600;
}

.wallet__row-ref {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}

.wallet__row-expiry {
  margin: 0;
  font-size: 0.75rem;
}

.wallet__row-date {
  margin: 0;
  font-size: 0.8125rem;
  text-align: right;
}

.wallet__row-amount {
  margin: 0;
  font-weight: 600;
}

.wallet__row-amount--in {
  color: var(--ln-ok);
}

@media (min-width: 720px) {
  .wallet__row {
    grid-template-columns: minmax(0, 1fr) 12rem 9rem auto;
  }

  .wallet__row-date,
  .wallet__row-amount {
    text-align: right;
  }
}

@media (min-width: 900px) {
  .wallet__grid {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  }
}
</style>
