<script setup>
/*
 * Daftar tiket perawatan untuk operator/admin, dengan filter status dan
 * prioritas. Tiket dibuat dari laporan masalah atau langsung dari aset.
 * Filter, state, dan pemanggilan API tidak berubah — hanya susunan
 * editorial, garis rule, dan gerak yang baru.
 */
import { nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { fmtDateTime, fmtPrice, TICKET_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from '../components/StatusBadge.vue'
import Pagination from '../components/Pagination.vue'
import LoadingState from '../components/LoadingState.vue'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import PageHead from '../components/ui/PageHead.vue'

const tickets = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const route = useRoute()
const router = useRouter()
const initial = readQuery(route, ['status', 'priority'])
const status = ref(initial.status ?? '')
const priority = ref(initial.priority ?? '')

const page = ref(null)
const motion = usePageMotion(page)

const STATUS_OPTIONS = [
  { value: 'open', label: 'Baru dibuat' },
  { value: 'assigned', label: 'Ditugaskan' },
  { value: 'in_progress', label: 'Sedang dikerjakan' },
  { value: 'awaiting_parts', label: 'Menunggu komponen' },
  { value: 'awaiting_verification', label: 'Menunggu verifikasi' },
  { value: 'resolved', label: 'Selesai' },
  { value: 'cancelled', label: 'Dibatalkan' },
]

const PRIORITY_OPTIONS = [
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
  if (priority.value) {
    params.set('priority', priority.value)
  }

  try {
    const payload = await apiGet(`/maintenance-tickets?${params.toString()}`)
    tickets.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat daftar tiket.'
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
  if (!revealed && tickets.value.length) {
    motion.reveal('.row', { stagger: 0.04, y: 14 })
    revealed = true
  }
  motion.refresh()
})

watch([status, priority], () => {
  writeQuery(router, route, { status: status.value, priority: priority.value })
  load(1)
})

onMounted(() => load())
</script>

<template>
  <section ref="page" class="tickets">
    <PageHead
      label="PERAWATAN"
      title="Perawatan aset"
      lede="Tiket perbaikan aset: penugasan, pengerjaan, verifikasi."
    >
      <template #actions>
        <RouterLink :to="{ name: 'ticket-create' }" class="btn btn--primary">
          Buat tiket
        </RouterLink>
      </template>
    </PageHead>

    <div class="filters rule-top">
      <p class="index-label">SARINGAN</p>
      <div class="filters__fields">
        <div class="field">
          <label for="ticket-status">Status</label>
          <select id="ticket-status" v-model="status" class="input">
            <option value="">Semua</option>
            <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
        <div class="field">
          <label for="ticket-priority">Prioritas</label>
          <select id="ticket-priority" v-model="priority" class="input">
            <option value="">Semua</option>
            <option v-for="opt in PRIORITY_OPTIONS" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <LoadingState v-if="loading" label="Memuat tiket" />
    <ErrorState v-else-if="error" :message="error" @retry="load(1)" />

    <div v-else class="results">
      <p class="index-label">DAFTAR</p>

      <EmptyState
        v-if="tickets.length === 0"
        title="Belum ada tiket"
        hint="Belum ada tiket perawatan yang cocok dengan filter."
      />

      <ul v-else class="list">
        <li v-for="ticket in tickets" :key="ticket.id" class="row">
          <RouterLink :to="{ name: 'ticket-detail', params: { id: ticket.id } }" class="row__link">
            <span class="row__main">
              <strong class="row__title">{{ ticket.asset?.name ?? 'Aset tidak tercatat' }}</strong>
              <span class="row__meta text-2">
                Prioritas {{ ticket.priority_label }}, dibuat {{ fmtDateTime(ticket.created_at) }}
                <template v-if="ticket.assignee">, teknisi {{ ticket.assignee.name }}</template>
                <template v-if="ticket.estimated_cost != null">
                  , perkiraan {{ fmtPrice(ticket.estimated_cost) }}
                </template>
              </span>
            </span>
            <span class="row__badges">
              <StatusBadge v-if="ticket.is_blocking" tone="err">Menghambat</StatusBadge>
              <StatusBadge :tone="TICKET_TONES[ticket.status] ?? 'neutral'">
                {{ ticket.status_label }}
              </StatusBadge>
            </span>
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
.tickets {
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

.row__badges {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-shrink: 0;
}

.row__link:hover .row__title {
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 44rem) {
  .filters__fields {
    grid-template-columns: 1fr;
  }

  .row__link {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    row-gap: 0.5rem;
  }

  .row__badges {
    justify-self: start;
    flex-wrap: wrap;
  }
}
</style>
