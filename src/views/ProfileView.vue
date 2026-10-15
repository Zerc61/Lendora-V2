<script setup>
/*
 * Halaman profil: identitas lengkap borrower (kontak, domisili, status
 * pendidikan, kontak darurat) agar admin bisa mengenali user saat perlu,
 * ditambah foto profil, ganti password, dan daftar keanggotaan organisasi.
 */
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { auth, logout, refreshMe } from '../lib/auth'
import { apiPost, apiPatch, ApiError } from '../lib/http'
import { roleLabel } from '../lib/roles'
import PageHead from '../components/ui/PageHead.vue'

const router = useRouter()

/* Nilai sesuai enum backend (User::EDUCATION_*). */
const EDUCATION_OPTIONS = [
  { value: 'siswa_smp', label: 'Siswa SMP' },
  { value: 'siswa_smk', label: 'Siswa SMK' },
  { value: 'siswa_sma', label: 'Siswa SMA' },
  { value: 'mahasiswa', label: 'Mahasiswa' },
  { value: 'umum', label: 'Umum' },
]

/* Guard router sudah memastikan /me termuat sebelum halaman ini dirender. */
const form = reactive({
  name: auth.user?.name ?? '',
  phone: auth.user?.phone ?? '',
  student_id: auth.user?.student_id ?? '',
  city: auth.user?.city ?? '',
  education_status: auth.user?.education_status ?? '',
  address: auth.user?.address ?? '',
  birth_place: auth.user?.birth_place ?? '',
  birth_date: auth.user?.birth_date ?? '',
  emergency_contact_name: auth.user?.emergency_contact_name ?? '',
  emergency_contact_phone: auth.user?.emergency_contact_phone ?? '',
})

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const identityBusy = ref(false)
const identityError = ref(null)
const identitySaved = ref(false)
const identityFieldErrors = ref({})

const passwordBusy = ref(false)
const passwordError = ref(null)
const passwordSaved = ref(false)
const passwordFieldErrors = ref({})

const avatarInput = ref(null)
const avatarBusy = ref(false)
const avatarError = ref(null)
// Avatar error handling for profile page
const profileAvatarError = ref(false)
// Base URL for avatar
const baseUrl = import.meta.env.VITE_APP_API_BASE
// Computed avatar URL for profile
const profileAvatarUrl = computed(() => {
  if (!auth.user?.avatar_url) return null
  if (auth.user.avatar_url.startsWith('http')) {
    return auth.user.avatar_url
  }
  return baseUrl + auth.user.avatar_url
})

const today = new Date().toISOString().slice(0, 10)

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join(''),
)

function formatDate(iso) {
  if (!iso) {
    return '—'
  }
  return new Date(iso).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

async function saveIdentity() {
  identityBusy.value = true
  identityError.value = null
  identitySaved.value = false
  identityFieldErrors.value = {}

  try {
    await apiPatch('/profile', { ...form })
    await refreshMe()
    identitySaved.value = true
  } catch (e) {
    if (e instanceof ApiError) {
      identityError.value = e.errors ? 'Periksa kembali isian di bawah.' : e.message
      identityFieldErrors.value = e.errors ?? {}
    } else {
      identityError.value = 'Gagal menyimpan identitas.'
    }
  } finally {
    identityBusy.value = false
  }
}

async function savePassword() {
  passwordBusy.value = true
  passwordError.value = null
  passwordSaved.value = false
  passwordFieldErrors.value = {}

  try {
    await apiPost('/profile/password', { ...passwordForm })
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    passwordSaved.value = true
  } catch (e) {
    if (e instanceof ApiError) {
      passwordError.value = e.errors ? 'Periksa kembali isian di bawah.' : e.message
      passwordFieldErrors.value = e.errors ?? {}
    } else {
      passwordError.value = 'Gagal mengganti password.'
    }
  } finally {
    passwordBusy.value = false
  }
}

function pickAvatar() {
  avatarInput.value?.click()
}

async function onAvatarChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) {
    return
  }

  avatarBusy.value = true
  avatarError.value = null

  const data = new FormData()
  data.append('avatar', file)

   try {
     const payload = await apiPost('/profile/avatar', data)
     auth.user.avatar_url = payload.data.avatar_url
     // Reset avatar error state when new avatar is successfully loaded
     profileAvatarError.value = false
   } catch (e) {
     avatarError.value = e instanceof ApiError ? e.message : 'Gagal mengunggah foto.'
   } finally {
     avatarBusy.value = false
   }
}

async function onLogout() {
  await logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <section class="profile">
    <PageHead
      label="PROFIL"
      title="Profil saya"
      lede="Identitas lengkap membantu admin mengenali Anda, misalnya saat ada kendala atau perlu konfirmasi langsung."
    />

    <div class="profile__grid">
      <div class="profile__main">
        <form class="panel profile__section" @submit.prevent="saveIdentity">
          <p class="index-label">IDENTITAS</p>
          <h2 class="profile__title">Data diri</h2>

          <p v-if="identityError" class="form-error" role="alert">{{ identityError }}</p>
          <p v-if="identitySaved" class="form-ok" role="status">Identitas diperbarui.</p>

          <div class="profile__fields">
            <div class="field">
              <label for="pf-name">Nama lengkap</label>
              <input
                id="pf-name"
                v-model="form.name"
                class="input"
                maxlength="255"
                autocomplete="name"
                required
              />
              <span v-if="identityFieldErrors.name" class="field-error">
                {{ identityFieldErrors.name[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-email">Email</label>
              <input
                id="pf-email"
                class="input"
                :value="auth.user?.email"
                readonly
                aria-readonly="true"
              />
              <span class="hint">Dipakai untuk masuk; hubungi admin untuk mengubahnya.</span>
            </div>

            <div class="field">
              <label for="pf-phone">No. HP</label>
              <input
                id="pf-phone"
                v-model="form.phone"
                class="input"
                maxlength="32"
                autocomplete="tel"
                placeholder="08…"
              />
              <span v-if="identityFieldErrors.phone" class="field-error">
                {{ identityFieldErrors.phone[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-student-id">NIS / NIM</label>
              <input
                id="pf-student-id"
                v-model="form.student_id"
                class="input"
                maxlength="24"
                placeholder="Nomor induk siswa atau mahasiswa"
              />
              <span v-if="identityFieldErrors.student_id" class="field-error">
                {{ identityFieldErrors.student_id[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-city">Kota</label>
              <input id="pf-city" v-model="form.city" class="input" maxlength="80" />
              <span v-if="identityFieldErrors.city" class="field-error">
                {{ identityFieldErrors.city[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-education">Status</label>
              <select id="pf-education" v-model="form.education_status" class="input">
                <option value="">Pilih status</option>
                <option v-for="opt in EDUCATION_OPTIONS" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <span v-if="identityFieldErrors.education_status" class="field-error">
                {{ identityFieldErrors.education_status[0] }}
              </span>
            </div>

            <div class="field profile__field--wide">
              <label for="pf-address">Alamat lengkap</label>
              <textarea
                id="pf-address"
                v-model="form.address"
                class="input"
                rows="3"
                maxlength="500"
                placeholder="Jalan, nomor, RT/RW, kecamatan"
              ></textarea>
              <span v-if="identityFieldErrors.address" class="field-error">
                {{ identityFieldErrors.address[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-birth-place">Tempat lahir</label>
              <input id="pf-birth-place" v-model="form.birth_place" class="input" maxlength="80" />
              <span v-if="identityFieldErrors.birth_place" class="field-error">
                {{ identityFieldErrors.birth_place[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-birth-date">Tanggal lahir</label>
              <input
                id="pf-birth-date"
                v-model="form.birth_date"
                class="input"
                type="date"
                :max="today"
              />
              <span v-if="identityFieldErrors.birth_date" class="field-error">
                {{ identityFieldErrors.birth_date[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-emergency-name">Nama kontak darurat</label>
              <input
                id="pf-emergency-name"
                v-model="form.emergency_contact_name"
                class="input"
                maxlength="100"
                placeholder="Orang tua, wali, kerabat"
              />
              <span v-if="identityFieldErrors.emergency_contact_name" class="field-error">
                {{ identityFieldErrors.emergency_contact_name[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-emergency-phone">No. HP kontak darurat</label>
              <input
                id="pf-emergency-phone"
                v-model="form.emergency_contact_phone"
                class="input"
                maxlength="32"
                placeholder="08…"
              />
              <span v-if="identityFieldErrors.emergency_contact_phone" class="field-error">
                {{ identityFieldErrors.emergency_contact_phone[0] }}
              </span>
            </div>
          </div>

          <button type="submit" class="btn btn--primary" :disabled="identityBusy">
            {{ identityBusy ? 'Menyimpan…' : 'Simpan identitas' }}
          </button>
        </form>

        <form class="panel profile__section" @submit.prevent="savePassword">
          <p class="index-label">KEAMANAN</p>
          <h2 class="profile__title">Ganti password</h2>

          <p v-if="passwordError" class="form-error" role="alert">{{ passwordError }}</p>
          <p v-if="passwordSaved" class="form-ok" role="status">Password berhasil diganti.</p>

          <div class="profile__fields">
            <div class="field">
              <label for="pf-current-password">Password sekarang</label>
              <input
                id="pf-current-password"
                v-model="passwordForm.current_password"
                class="input"
                type="password"
                autocomplete="current-password"
                required
              />
              <span v-if="passwordFieldErrors.current_password" class="field-error">
                {{ passwordFieldErrors.current_password[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-new-password">Password baru</label>
              <input
                id="pf-new-password"
                v-model="passwordForm.password"
                class="input"
                type="password"
                autocomplete="new-password"
                minlength="8"
                required
              />
              <span class="hint">Minimal 8 karakter.</span>
              <span v-if="passwordFieldErrors.password" class="field-error">
                {{ passwordFieldErrors.password[0] }}
              </span>
            </div>

            <div class="field">
              <label for="pf-confirm-password">Ulangi password baru</label>
              <input
                id="pf-confirm-password"
                v-model="passwordForm.password_confirmation"
                class="input"
                type="password"
                autocomplete="new-password"
                minlength="8"
                required
              />
            </div>
          </div>

          <button type="submit" class="btn btn--primary" :disabled="passwordBusy">
            {{ passwordBusy ? 'Menyimpan…' : 'Ganti password' }}
          </button>
        </form>
      </div>

      <aside class="profile__side">
        <div class="panel profile__section">
          <p class="index-label">FOTO</p>
          <h2 class="profile__title">Foto profil</h2>

            <div class="profile__avatar-row">
              <img
                v-if="profileAvatarUrl && !profileAvatarError"
                class="profile__avatar"
                :src="profileAvatarUrl"
                :alt="`Foto ${auth.user?.name ?? ''}`"
                @error="profileAvatarError = true"
              />
              <span v-else class="profile__avatar profile__avatar--initials num" aria-hidden="true">
                {{ initials }}
              </span>
              <div class="profile__avatar-actions">
                <input
                  ref="avatarInput"
                  type="file"
                  accept="image/*"
                  class="visually-hidden"
                  tabindex="-1"
                  @change="onAvatarChange"
                />
                <button type="button" class="btn" :disabled="avatarBusy" @click="pickAvatar">
                  {{ avatarBusy ? 'Mengunggah…' : 'Ganti foto' }}
                </button>
                <span class="hint">JPG, PNG, atau WebP; maksimal 2 MB.</span>
              </div>
            </div>

          <p v-if="avatarError" class="form-error" role="alert">{{ avatarError }}</p>
        </div>

        <div class="panel profile__section">
          <p class="index-label">AKUN</p>
          <h2 class="profile__title">Status akun</h2>

          <dl class="profile__facts">
            <div>
              <dt>Peran</dt>
              <dd>{{ roleLabel(auth.role) }}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd>{{ auth.user?.status_label ?? '—' }}</dd>
            </div>
            <div>
              <dt>Bergabung</dt>
              <dd>{{ formatDate(auth.user?.joined_at) }}</dd>
            </div>
            <div>
              <dt>Terakhir masuk</dt>
              <dd>{{ formatDate(auth.user?.last_login_at) }}</dd>
            </div>
          </dl>
        </div>

        <div class="panel profile__section">
          <p class="index-label">ORGANISASI</p>
          <h2 class="profile__title">Keanggotaan</h2>

          <ul class="profile__orgs">
            <li v-for="org in auth.organizations" :key="org.id">
              {{ org.name }}
              <span v-if="org.id === auth.activeOrganizationId" class="profile__flag">aktif</span>
            </li>
            <li v-if="!auth.organizations.length" class="text-2">Belum ada organisasi.</li>
          </ul>

          <button type="button" class="btn btn--ghost" @click="onLogout">Keluar</button>
        </div>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.profile {
  display: grid;
  gap: 2rem;
}

.profile__grid {
  display: grid;
  gap: 1.5rem;
}

.profile__main,
.profile__side {
  display: grid;
  gap: 1.5rem;
  align-content: start;
}

.profile__section {
  display: grid;
  gap: 0.5rem;
}

.profile__title {
  margin: 0 0 0.75rem;
  font-size: 1.25rem;
}

/* Alamat (wide) tetap satu baris penuh di dua kolom. */
.profile__fields {
  display: grid;
  gap: 0 1.25rem;
  margin-bottom: 0.5rem;
}

.profile__field--wide {
  grid-column: 1 / -1;
}

.profile__avatar-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.5rem;
}

.profile__avatar {
  width: 4.5rem;
  height: 4.5rem;
  flex: none;
  border: 1px solid var(--ln-border-strong);
  border-radius: 999px;
  background: var(--ln-surface-alt);
  object-fit: cover;
}

.profile__avatar--initials {
  display: grid;
  place-items: center;
  font-size: 1.125rem;
  font-weight: 700;
}

.profile__avatar-actions {
  display: grid;
  gap: 0.375rem;
  justify-items: start;
}

.profile__facts {
  display: grid;
  gap: 0.5rem;
  margin: 0;
}

.profile__facts > div {
  display: grid;
  grid-template-columns: 8rem minmax(0, 1fr);
  gap: 0.75rem;
  font-size: 0.875rem;
}

.profile__facts dt {
  padding-top: 0.125rem;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.profile__facts dd {
  margin: 0;
}

.profile__orgs {
  display: grid;
  gap: 0.375rem;
  margin: 0 0 1.25rem;
  padding: 0;
  list-style: none;
}

.profile__flag {
  margin-left: 0.375rem;
  color: var(--ln-info);
  font-family: var(--ln-font-mono);
  font-size: 0.625rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.form-ok {
  margin: 0 0 1rem;
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--ln-ok);
  color: var(--ln-ok);
  background: var(--ln-ok-bg);
}

/* Tombol ghost (Keluar) jangan ikut melebar penuh seperti tombol simpan. */
.profile__section .btn--ghost {
  width: auto;
  justify-self: start;
}

@media (min-width: 40rem) {
  .profile__fields {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 64rem) {
  .profile__grid {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
    align-items: start;
  }
}
</style>
