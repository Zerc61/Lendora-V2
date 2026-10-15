<script setup>
/*
 * Kebijakan organisasi (admin). Menampilkan dan memperbarui pengaturan
 * peminjaman organisasi aktif dari GET/PATCH organizations/{id}/policy.
 * Gagal memuat (termasuk 403/404) tampil sebagai ErrorState — tanpa
 * nilai default palsu.
 *
 * Presentasi editorial: dokumen pasal bernomor mono di kiri, judul dan isi
 * di kanan, garis rule antar pasal, reveal berurutan (lib/motion.js).
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { apiGet, apiPatch, ApiError } from '../lib/http'
import { toast } from '../lib/toast'
import { auth } from '../lib/auth'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const policy = ref(null)
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const saveError = ref(null)

const page = ref(null)
const motion = usePageMotion(page)

const activeOrg = computed(
  () => auth.organizations.find((o) => o.id === auth.activeOrganizationId) ?? null,
)

async function load() {
  if (!activeOrg.value) return
  loading.value = true
  error.value = null
  try {
    const payload = await apiGet(`/organizations/${activeOrg.value.id}/policy`)
    policy.value = payload.data ?? payload
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat kebijakan.'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!activeOrg.value) return
  saving.value = true
  saveError.value = null
  try {
    await apiPatch(`/organizations/${activeOrg.value.id}/policy`, policy.value)
    toast.ok('Kebijakan berhasil disimpan.')
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'Gagal menyimpan kebijakan.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await load()
  await nextTick()
  motion.reveal('.clause', { stagger: 0.08 })
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="policy">
    <PageHead
      label="KEBIJAKAN"
      title="Kebijakan peminjaman"
      lede="Pengaturan default peminjaman untuk organisasi aktif."
    />

    <LoadingState v-if="loading" label="Memuat kebijakan" />

    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else-if="policy">
      <p v-if="saveError" class="form-error" role="alert">{{ saveError }}</p>

      <aside class="note">
        <p class="note__label"><b>CATATAN</b></p>
        <p class="note__body">
          Kebijakan ini menentukan aturan default peminjaman untuk seluruh aset di organisasi ini.
          Perubahan berlaku untuk pengajuan berikutnya.
        </p>
      </aside>

      <form class="policy-form" @submit.prevent="save">
        <article class="clause">
          <p class="clause__index num" aria-hidden="true">PASAL 01</p>
          <fieldset class="clause__fields">
            <legend class="clause__title">Durasi Peminjaman</legend>

            <div class="field">
              <label for="max-loan-duration">Durasi maksimal (hari)</label>
              <input
                id="max-loan-duration"
                v-model.number="policy.max_loan_duration_days"
                class="input"
                type="number"
                min="1"
                max="365"
              />
              <p class="hint">Berapa hari maksimal satu peminjaman bisa berlangsung.</p>
            </div>

            <div class="field">
              <label for="min-loan-duration">Durasi minimal (jam)</label>
              <input
                id="min-loan-duration"
                v-model.number="policy.min_loan_duration_hours"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">Minimal berapa jam sebuah peminjaman harus berlangsung.</p>
            </div>
          </fieldset>
        </article>

        <article class="clause">
          <p class="clause__index num" aria-hidden="true">PASAL 02</p>
          <fieldset class="clause__fields">
            <legend class="clause__title">Jadwal Pengajuan</legend>

            <div class="field">
              <label for="max-advance">Maksimal pengajuan di muka (hari)</label>
              <input
                id="max-advance"
                v-model.number="policy.max_advance_days"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">Berapa hari ke depan peminjam bisa mengajukan peminjaman.</p>
            </div>

            <div class="field">
              <label for="min-notice">Pemberitahuan minimal (jam)</label>
              <input
                id="min-notice"
                v-model.number="policy.min_notice_hours"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">Berapa jam sebelum mulai, peminjaman harus sudah diajukan.</p>
            </div>

            <div class="field">
              <label for="pickup-window">Jendela pengambilan (jam)</label>
              <input
                id="pickup-window"
                v-model.number="policy.pickup_window_hours"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">
                Berapa jam setelah waktu mulai, aset yang disetujui masih bisa diambil.
              </p>
            </div>
          </fieldset>
        </article>

        <article class="clause">
          <p class="clause__index num" aria-hidden="true">PASAL 03</p>
          <fieldset class="clause__fields">
            <legend class="clause__title">Batas Peminjaman Aktif</legend>

            <div class="field">
              <label for="max-active">Maksimal pinjaman aktif per peminjam</label>
              <input
                id="max-active"
                v-model.number="policy.max_active_loans"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">
                Berapa banyak pinjaman yang boleh berjalan bersamaan untuk satu peminjam.
              </p>
            </div>
          </fieldset>
        </article>

        <article class="clause">
          <p class="clause__index num" aria-hidden="true">PASAL 04</p>
          <fieldset class="clause__fields">
            <legend class="clause__title">Aturan Proses</legend>

            <div class="toggle-field">
              <input
                id="require-approval"
                v-model="policy.require_approval"
                type="checkbox"
                class="toggle"
              />
              <label for="require-approval">
                <strong>Wajib persetujuan operator</strong>
                <span class="hint">Permintaan peminjaman harus disetujui sebelum diambil.</span>
              </label>
            </div>

            <div class="toggle-field">
              <input
                id="require-pre-checkout"
                v-model="policy.require_pre_checkout_inspection"
                type="checkbox"
                class="toggle"
              />
              <label for="require-pre-checkout">
                <strong>Wajib inspeksi sebelum serah terima</strong>
                <span class="hint">Kondisi aset dicatat saat checkout.</span>
              </label>
            </div>

            <div class="toggle-field">
              <input
                id="require-post-return"
                v-model="policy.require_post_return_inspection"
                type="checkbox"
                class="toggle"
              />
              <label for="require-post-return">
                <strong>Wajib inspeksi setelah pengembalian</strong>
                <span class="hint">Aset harus diinspeksi sebelum kembali aktif.</span>
              </label>
            </div>

            <div class="toggle-field">
              <input
                id="allow-borrower-cancel"
                v-model="policy.allow_borrower_cancel"
                type="checkbox"
                class="toggle"
              />
              <label for="allow-borrower-cancel">
                <strong>Peminjam dapat membatalkan</strong>
                <span class="hint">Peminjam diizinkan membatalkan sendiri setelah disetujui.</span>
              </label>
            </div>
          </fieldset>
        </article>

        <article class="clause">
          <p class="clause__index num" aria-hidden="true">PASAL 05</p>
          <fieldset class="clause__fields">
            <legend class="clause__title">Perawatan</legend>

            <div class="field">
              <label for="maintenance-notice">Pemberitahuan perawatan terjadwal (hari)</label>
              <input
                id="maintenance-notice"
                v-model.number="policy.maintenance_notice_days"
                class="input"
                type="number"
                min="1"
              />
              <p class="hint">
                Berapa hari sebelumnya operator diberi tahu soal perawatan terjadwal yang
                bertabrakan dengan pinjaman aktif.
              </p>
            </div>
          </fieldset>
        </article>

        <div class="form-actions">
          <button type="submit" class="btn btn--primary" :disabled="saving">
            {{ saving ? 'Menyimpan...' : 'Simpan kebijakan' }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.policy {
  display: grid;
  gap: 2.5rem;
}

/* Catatan monokron: label mono, garis rule kiri tebal. */
.note {
  display: grid;
  gap: 0.5rem;
  max-width: 46rem;
  padding: 1rem 1.25rem;
  border: 1px solid var(--ln-border);
  border-left: 2px solid var(--ln-border-strong);
  background: var(--ln-surface-alt);
}

.note__label {
  margin: 0;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
}

.note__label b {
  color: var(--ln-text);
  font-weight: 600;
}

.note__body {
  margin: 0;
  max-width: 62ch;
  color: var(--ln-text-2);
  font-size: 0.9375rem;
  line-height: 1.7;
}

/* Dokumen kebijakan: pasal bernomor mono di kiri, isi di kanan. */
.policy-form {
  display: grid;
  gap: 0;
  max-width: 56rem;
}

.clause {
  display: grid;
  grid-template-columns: 7rem minmax(0, 1fr);
  gap: 1.5rem;
  padding: 2rem 0;
  border-top: 1px solid var(--ln-border);
}

.clause:first-child {
  border-top-width: 2px;
  border-top-color: var(--ln-border-strong);
  padding-top: 1.5rem;
}

.clause__index {
  margin: 0;
  padding-top: 0.5rem;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.14em;
}

.clause__fields {
  display: block;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
  max-width: 34rem;
}

.clause__title {
  display: block;
  width: 100%;
  margin: 0 0 1.5rem;
  padding: 0;
  font-family: var(--ln-font-display);
  font-size: var(--ln-title);
  font-weight: 500;
  letter-spacing: -0.035em;
  color: var(--ln-text);
}

.field {
  display: grid;
  gap: 0.375rem;
  margin: 0 0 1.25rem;
}

.field:last-child {
  margin-bottom: 0;
}

.field label {
  font-weight: 600;
  font-size: 0.9375rem;
}

.hint {
  font-size: 0.8125rem;
  color: var(--ln-text-2);
  margin: 0;
}

/* Baris aturan proses: bergaris rule, target besar. */
.toggle-field {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  min-height: 44px;
  padding: 0.875rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.toggle-field:first-of-type {
  border-top: 1px solid var(--ln-border);
}

.toggle {
  flex-shrink: 0;
  width: 1.25rem;
  height: 1.25rem;
  margin-top: 0.15rem;
  cursor: pointer;
}

.toggle-field label {
  display: grid;
  gap: 0.25rem;
  cursor: pointer;
}

.toggle-field label strong {
  font-size: 0.9375rem;
}

.toggle-field label .hint {
  color: var(--ln-text-2);
}

.form-actions {
  display: flex;
  gap: 0.75rem;
  padding-top: 2rem;
  border-top: 2px solid var(--ln-border-strong);
}

@media (max-width: 48rem) {
  .clause {
    grid-template-columns: 1fr;
    gap: 0.75rem;
    padding: 1.5rem 0;
  }

  .clause__index {
    padding-top: 0;
  }
}
</style>
