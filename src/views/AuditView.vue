<script setup>
/*
 * Jejak audit organisasi (admin). Daftar kronologis dari /audit-events:
 * siapa, melakukan apa, pada objek mana, plus diff before/after yang
 * dilipat (append-only, tanpa aksi tulis).
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, ApiError } from '../lib/http'
import { readQuery, writeQuery } from '../lib/filterQuery'
import { fmtDateTime } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import LoadingState from '../components/LoadingState.vue'
import ErrorState from '../components/ErrorState.vue'
import EmptyState from '../components/EmptyState.vue'
import Pagination from '../components/Pagination.vue'
import PageHead from '../components/ui/PageHead.vue'

const events = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref(null)

const filterAction = ref('')

const route = useRoute()
const router = useRouter()

const page = ref(null)
const motion = usePageMotion(page)

async function load(pageNumber = 1) {
  loading.value = true
  error.value = null
  const params = new URLSearchParams()
  if (filterAction.value) params.set('action', filterAction.value)
  params.set('page', String(pageNumber))
  try {
    const payload = await apiGet(`/audit-events?${params.toString()}`)
    events.value = payload.data ?? []
    meta.value = payload.meta ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat jejak audit.'
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  writeQuery(router, route, { action: filterAction.value.trim() })
  load(1)
}

function resetFilters() {
  filterAction.value = ''
  applyFilters()
}

function formatDiff(diff) {
  return JSON.stringify(diff, null, 2)
}

onMounted(async () => {
  filterAction.value = readQuery(route, ['action']).action ?? ''

  await load()
  motion.reveal('.audit-row', { stagger: 0.03 })
  motion.refresh()
})
</script>

<template>
  <section ref="page" class="audit">
    <PageHead
      label="AUDIT"
      title="Jejak audit"
      lede="Catatan perubahan yang terjadi di organisasi ini: siapa melakukan apa, pada objek mana, dan perubahannya. Bersifat hanya-tambah, tidak bisa diedit atau dihapus."
    />

    <form class="filters" @submit.prevent="applyFilters">
      <div class="field">
        <label for="a-action" class="filters__label">Aksi</label>
        <input
          id="a-action"
          v-model="filterAction"
          class="input"
          maxlength="100"
          placeholder="mis. membership.updated"
        />
      </div>
      <div class="filters__actions">
        <button type="submit" class="btn btn--primary">Terapkan</button>
        <button type="button" class="btn" @click="resetFilters">Reset</button>
      </div>
    </form>

    <LoadingState v-if="loading" label="Memuat jejak audit" />

    <ErrorState v-else-if="error" :message="error" @retry="() => load()" />

    <template v-else-if="events.length">
      <ul class="trail" aria-label="Daftar kejadian audit">
        <li v-for="event in events" :key="event.id" class="audit-row">
          <div class="audit-row__head">
            <span class="audit-row__action num">{{ event.action }}</span>
            <span class="audit-row__time num">{{ fmtDateTime(event.created_at) }}</span>
          </div>

          <p class="audit-row__who">
            <template v-if="event.actor">
              {{ event.actor.name }} <span class="text-2 num">{{ event.actor.email }}</span>
            </template>
            <span v-else class="text-2">Sistem</span>
            <span class="text-2"> · {{ event.subject_type }}#{{ event.subject_id ?? '—' }} </span>
            <span v-if="event.ip_address" class="text-2 num"> · {{ event.ip_address }}</span>
          </p>

          <details v-if="event.before || event.after" class="audit-row__diff">
            <summary>Lihat perubahan</summary>
            <pre class="num">{{ formatDiff({ before: event.before, after: event.after }) }}</pre>
          </details>
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

    <EmptyState
      v-else
      title="Belum ada kejadian"
      hint="Belum ada perubahan yang tercatat untuk filter ini."
    />
  </section>
</template>

<style scoped>
.audit {
  display: grid;
  gap: 2rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem 1rem;
  padding: 1.25rem 0;
  border-block: 1px solid var(--ln-border);
}

.filters .field {
  margin: 0;
  min-width: 16rem;
}

.filters__label {
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.filters__actions {
  display: flex;
  gap: 0.5rem;
}

.trail {
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.audit-row {
  display: grid;
  gap: 0.375rem;
  padding: 0.875rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.audit-row__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem 1rem;
}

.audit-row__action {
  font-size: 0.875rem;
  font-weight: 600;
}

.audit-row__time {
  color: var(--ln-text-2);
  font-size: 0.8125rem;
}

.audit-row__who {
  margin: 0;
  font-size: 0.875rem;
}

.audit-row__diff summary {
  cursor: pointer;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.audit-row__diff pre {
  margin: 0.5rem 0 0;
  padding: 0.75rem;
  overflow-x: auto;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface-alt);
  font-size: 0.75rem;
  line-height: 1.6;
}

@media (max-width: 48rem) {
  .filters {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
