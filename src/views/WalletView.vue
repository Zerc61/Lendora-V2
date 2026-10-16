<script setup>
/*
 * Dompet: saldo, isi saldo lewat Midtrans, dan riwayat transaksi.
 *
 * Saldo tidak dihitung di klien. Angka datang dari /wallet/balance, dan saldo
 * baru bertambah setelah Midtrans mengabari server (webhook atau rekonsiliasi),
 * bukan setelah pengguna menutup halaman pembayaran. Karena itu halaman ini
 * menyediakan tombol muat ulang, bukan pembaruan optimistis.
 */
import { onMounted, ref } from 'vue'
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
 * Batas nominal datang dari server (`/wallet/balance`); nilai di lib/wallet.js
 * hanya cadangan sampai jawaban pertama tiba.
 */
const PRESETS = [10000, 50000, 100000, 250000]

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
const limits = ref(topUpLimits(null))

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

async function topup() {
  const message = topUpErrorMessage(topupAmount.value, limits.value)

  if (message) {
    topupError.value = message
    return
  }

  topupBusy.value = true
  topupError.value = null

  try {
    const payload = await apiPost('/wallet/topup', { amount: topupAmount.value })
    const data = payload?.data ?? {}

    // Snap di halaman ini; kalau skripnya tidak bisa dimuat, lanjut ke halaman
    // pembayaran Midtrans. Saldo tetap menunggu notifikasi ke server.
    const opened = await payWithSnap({
      token: data.snap_token,
      clientKey: data.snap_client_key,
      isProduction: data.snap_is_production === true,
      onSettled: refresh,
    })

    if (opened) {
      return
    }

    if (!data.redirect_url) {
      topupError.value = 'Tautan pembayaran tidak tersedia. Coba lagi.'
      return
    }

    window.location.assign(data.redirect_url)
  } catch (e) {
    topupError.value = e instanceof ApiError ? e.message : 'Gagal memulai pembayaran.'
  } finally {
    topupBusy.value = false
  }
}

onMounted(refresh)
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
        <p v-if="balanceError" class="field-error" role="alert">{{ balanceError }}</p>
      </div>

      <form class="panel wallet__card" @submit.prevent="topup">
        <p class="index-label">ISI SALDO</p>

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
            Minimal {{ fmtPrice(limits.min) }}, maksimal {{ fmtPrice(limits.max) }} per transaksi.
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
          Pembayaran diproses Midtrans. Saldo bertambah otomatis setelah Midtrans mengonfirmasi ke
          server, jadi saldo bisa tertinggal beberapa detik dari pembayaranmu.
        </p>
      </form>
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

.wallet__presets {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.wallet__preset {
  min-height: 36px;
  padding: 0.25rem 0.75rem;
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
