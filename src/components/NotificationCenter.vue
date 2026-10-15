<script setup>
/*
 * Pusat notifikasi in-app Borrower.
 *
 * Data dari GET /notifications dan /notifications/unread-count. Penandaan
 * dibaca selalu menunggu konfirmasi backend sebelum tampil berubah, dan
 * tautan aksi menuju transaksi terkait memakai `action_url` dari payload.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  BadgeCheck,
  Bell,
  CalendarClock,
  CircleCheck,
  CircleX,
  Clock,
  PackageCheck,
  TriangleAlert,
  Wrench,
} from '@lucide/vue'
import { apiGet, apiPost, ApiError } from '../lib/http'
import { fmtDateTime, fmtRelative } from '../lib/format'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'

// Lencana notifikasi di navbar ikut diperbarui lewat jumlah belum dibaca.
const emit = defineEmits(['unread'])

const PAGE_SIZE = 5

const KEY_ICONS = {
  'loan.approved': CircleCheck,
  'loan.rejected': CircleX,
  'loan.requested': Clock,
  'loan.pickup_soon': PackageCheck,
  'loan.due_soon': CalendarClock,
  'loan.overdue': TriangleAlert,
  'loan.return_confirmed': BadgeCheck,
  'loan.checked_out': PackageCheck,
  'loan.cancelled': CircleX,
  'issue.reported': Wrench,
}

const items = ref([])
const meta = ref(null)
const unread = ref(0)
const visible = ref(PAGE_SIZE)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref(null)
const actionError = ref(null)

const hasMore = computed(() => {
  if (visible.value < items.value.length) {
    return true
  }
  return (meta.value?.current_page ?? 1) < (meta.value?.last_page ?? 1)
})

async function load() {
  loading.value = true
  error.value = null

  try {
    const [list, counts] = await Promise.all([
      apiGet('/notifications?page=1'),
      apiGet('/notifications/unread-count'),
    ])
    items.value = list.data ?? []
    meta.value = list.meta ?? null
    unread.value = counts.count ?? 0
    visible.value = PAGE_SIZE
    emit('unread', unread.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat notifikasi.'
    items.value = []
    unread.value = 0
    emit('unread', 0)
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (visible.value < items.value.length) {
    visible.value += PAGE_SIZE
    return
  }

  if (!meta.value || meta.value.current_page >= meta.value.last_page) {
    return
  }

  loadingMore.value = true
  actionError.value = null

  try {
    const next = await apiGet(`/notifications?page=${meta.value.current_page + 1}`)
    items.value = [...items.value, ...(next.data ?? [])]
    meta.value = next.meta ?? meta.value
    visible.value += PAGE_SIZE
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal memuat notifikasi berikutnya.'
  } finally {
    loadingMore.value = false
  }
}

async function markRead(notification) {
  if (notification.read_at) {
    return true
  }

  actionError.value = null

  try {
    const payload = await apiPost(`/notifications/${notification.id}/read`)
    notification.read_at = payload?.data?.read_at ?? new Date().toISOString()
    unread.value = Math.max(0, unread.value - 1)
    emit('unread', unread.value)
    return true
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal menandai notifikasi.'
    return false
  }
}

async function markAllRead() {
  actionError.value = null

  try {
    await apiPost('/notifications/read-all')
    const stamp = new Date().toISOString()
    for (const notification of items.value) {
      notification.read_at ??= stamp
    }
    unread.value = 0
    emit('unread', 0)
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal menandai semua notifikasi.'
  }
}

function iconFor(key) {
  return KEY_ICONS[key] ?? Bell
}

onMounted(load)
</script>

<template>
  <div class="notes">
    <div class="notes__head">
      <p class="notes__count">
        <span v-if="unread" class="notes__badge num">{{ unread }} belum dibaca</span>
        <span v-else class="text-2">Semua sudah dibaca</span>
      </p>

      <button type="button" class="btn btn--ghost" :disabled="!unread" @click="markAllRead">
        Tandai semua sudah dibaca
      </button>
    </div>

    <LoadingState v-if="loading" label="Memuat notifikasi" />

    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <EmptyState
      v-else-if="!items.length"
      title="Belum ada notifikasi"
      hint="Kabar persetujuan, penolakan, pengambilan, dan tenggat pengembalian akan muncul di sini."
    />

    <template v-else>
      <p v-if="actionError" class="notes__error" role="alert">{{ actionError }}</p>

      <ul class="notes__list">
        <li
          v-for="notification in items.slice(0, visible)"
          :key="notification.id"
          class="note"
          :class="{ 'note--unread': !notification.read_at }"
        >
          <span class="note__icon" aria-hidden="true">
            <component :is="iconFor(notification.key)" :size="16" />
          </span>

          <div class="note__body">
            <p class="note__title">
              {{ notification.title }}
              <span v-if="!notification.read_at" class="note__flag">Baru</span>
            </p>
            <p class="note__text text-2">{{ notification.body }}</p>
            <p class="note__meta num" :title="fmtDateTime(notification.created_at)">
              {{ fmtRelative(notification.created_at) }}
            </p>
          </div>

          <div class="note__actions">
            <RouterLink
              v-if="notification.action_url"
              class="note__link link-sweep"
              :to="notification.action_url"
              @click="markRead(notification)"
            >
              {{ notification.action_label ?? 'Lihat detail' }}
            </RouterLink>

            <button
              v-if="!notification.read_at"
              type="button"
              class="note__read"
              @click="markRead(notification)"
            >
              Tandai dibaca
            </button>
          </div>
        </li>
      </ul>

      <button
        v-if="hasMore"
        type="button"
        class="btn btn--ghost notes__more"
        :disabled="loadingMore"
        @click="loadMore"
      >
        {{ loadingMore ? 'Memuat…' : 'Muat lebih banyak' }}
      </button>
    </template>
  </div>
</template>

<style scoped>
.notes {
  display: grid;
  gap: 1rem;
}

.notes__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--ln-border);
}

.notes__count {
  margin: 0;
}

.notes__badge {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--ln-warn);
  color: var(--ln-warn);
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.notes__error {
  margin: 0;
  padding: 0.75rem;
  border: 1px solid var(--ln-err);
  background: var(--ln-err-bg);
  color: var(--ln-err);
  font-size: 0.875rem;
}

.notes__list {
  display: grid;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.note {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 0.875rem;
  align-items: start;
  padding: 1rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.note--unread {
  background: var(--ln-surface-alt);
  padding-inline: 0.75rem;
}

.note__icon {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border: 1px solid var(--ln-border);
  color: var(--ln-text);
}

.note--unread .note__icon {
  border-color: var(--ln-border-strong);
}

.note__body {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.note__title {
  margin: 0;
  font-weight: 600;
}

.note__flag {
  display: inline-block;
  margin-left: 0.4rem;
  padding: 0 0.375rem;
  border: 1px solid var(--ln-info);
  color: var(--ln-info);
  font-family: var(--ln-font-mono);
  font-size: 0.625rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  vertical-align: middle;
}

.note__text {
  margin: 0;
  font-size: 0.875rem;
  line-height: 1.55;
}

.note__meta {
  margin: 0;
  color: var(--ln-text-2);
  font-size: 0.6875rem;
  letter-spacing: 0.06em;
}

.note__actions {
  display: grid;
  gap: 0.5rem;
  justify-items: end;
}

.note__link {
  color: var(--ln-text);
  font-size: 0.8125rem;
  white-space: nowrap;
}

.note__read {
  min-height: 44px;
  padding: 0 0.5rem;
  border: 1px solid var(--ln-border);
  background: none;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: 0.625rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  cursor: pointer;
  transition:
    border-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

.note__read:hover {
  border-color: var(--ln-border-strong);
  color: var(--ln-text);
}

.notes__more {
  justify-self: start;
}

@media (max-width: 40rem) {
  .note {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .note__actions {
    grid-column: 1 / -1;
    justify-items: start;
    padding-left: calc(30px + 0.875rem);
  }
}
</style>
