<script setup>
/*
 * Daftar laporan masalah. Borrower melihat laporannya sendiri dan dapat
 * melaporkan masalah baru; operator/admin melihat semua laporan dengan
 * filter status dan severity. Filter, state, dan pemanggilan API tidak
 * berubah — hanya susunan editorial, garis rule, dan gerak yang baru.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { auth } from '../lib/auth'
import { fmtDateTime, ISSUE_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import Pagination from '../components/Pagination.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const issues = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const route = useRoute()
const router = useRouter()
const initial = readQuery(route, ['status', 'severity'])
const status = ref(initial.status ?? '')
const severity = ref(initial.severity ?? '')

const page = ref(null)
const motion = usePageMotion(page)

const isManagement = computed(() => auth.role === 'operator' || auth.role === 'admin')

const STATUS_OPTIONS = [
  { value: 'open', label: 'Dilaporkan' },
  { value: 'reviewing', label: 'Ditinjau' },
  { value: 'resolved', label: 'Selesai' },
  { value: 'dismissed', label: 'Bukan masalah' },
]

const SEVERITY_OPTIONS = [
  { value: 'low', label: 'Rendah' },
  { value: 'medium', label: 'Sedang' },
  { value: 'high', label: 'Tinggi' },
  { value: 'critical', label: 'Kritis' },
]

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null

  const params = new URLSearchParams({ page: String(pageNumber) })
  if (status.value) {
    params.set('status', status.value)
  }
  if (severity.value) {
    params.set('severity', severity.value)
  }

  try {
    const payload = await apiGet(`/issues?${params.toString()}`)
    issues.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar laporan.'
  } finally {
    loading.value = false
  }
}

/* Reveal hanya sekali, setelah baris benar-benar ada di DOM. */
let revealed = false
watch(loading, async (isLoading) => {
  if (isLoading) {
    return
  }
  await nextTick()
  if (!revealed && issues.value.length) {
    motion.reveal('.row', { stagger: 0.04, y: 14 })
    revealed = true
  }
  motion.refresh()
})

watch([status, severity], () => {
  writeQuery(router, route, { status: status.value, severity: severity.value })
  load(1)
})

onMounted(() => load())
</script>

<template>
  <section ref="page" class="issues">
    <PageHead
      label="LAPORAN MASALAH"
      :title="isManagement ? 'Laporan masalah' : 'Laporan saya'"
      :lede="
        isManagement
          ? 'Semua laporan kerusakan, kehilangan, dan masalah lain dari anggota.'
          : 'Laporan kerusakan atau kehilangan yang pernah kamu kirim.'
      "
    >
      <template #actions>
        <RouterLink :to="{ name: 'issue-create' }" class="btn btn--primary">
          Laporkan masalah
        </RouterLink>
      </template>
    </PageHead>

    <div class="filters rule-top">
      <p class="index-label">SARINGAN</p>
      <div class="filters__fields">
        <div class="field">
          <label for="issue-status">Status</label>
          <select id="issue-status" v-model="status" class="input">
            <option value="">Semua</option>
            <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
        <div class="field">
          <label for="issue-severity">Severity</label>
          <select id="issue-severity" v-model="severity" class="input">
            <option value="">Semua</option>
            <option v-for="opt in SEVERITY_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <LoadingState v-if="loading" label="Memuat laporan" />
    <ErrorState v-else-if="error" :message="error" @retry="load(1)" />

    <div v-else class="results">
      <p class="index-label">DAFTAR</p>

      <EmptyState
        v-if="issues.length === 0"
        title="Belum ada laporan"
        hint="Belum ada laporan masalah yang cocok dengan filter."
      />

      <ul v-else class="list">
        <li v-for="issue in issues" :key="issue.id" class="row">
          <RouterLink :to="{ name: 'issue-detail', params: { id: issue.id } }" class="row__link">
            <span class="row__main">
              <strong class="row__title">{{ issue.asset?.name ?? 'Aset tidak tercatat' }}</strong>
              <span class="row__meta text-2">
                {{ issue.type_label }} ({{ issue.severity_label }}),
                {{ fmtDateTime(issue.created_at) }}
                <template v-if="isManagement && issue.reporter">
                  oleh {{ issue.reporter.name }}
                </template>
              </span>
            </span>
            <StatusBadge :tone="ISSUE_TONES[issue.status] ?? 'neutral'">
              {{ issue.status_label }}
            </StatusBadge>
          </RouterLink>
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
    </div>
  </section>
</template>

<style scoped>
.issues {
  display: grid;
  gap: 2rem;
}

.filters {
  display: grid;
  gap: 0.75rem;
}

.filters .index-label {
  margin-bottom: 0;
}

.filters__fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 12rem));
  gap: 0 1rem;
}

.filters__fields .field {
  margin-bottom: 0;
}

.results {
  display: grid;
  gap: 1rem;
}

.results .index-label {
  margin-bottom: 0;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.row {
  border-bottom: 1px solid var(--ln-border);
}

.row__link {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  padding: 0.75rem 0;
  color: var(--ln-text);
  text-decoration: none;
}

.row__main {
  display: grid;
  gap: 0.125rem;
}

.row__title {
  font-weight: 600;
}

.row__meta {
  font-size: 0.875rem;
}

.row__link:hover .row__title {
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 40rem) {
  .filters__fields {
    grid-template-columns: 1fr;
  }

  .row__link {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    row-gap: 0.5rem;
  }

  .row__link :deep(.badge) {
    justify-self: start;
  }
}
</style>
