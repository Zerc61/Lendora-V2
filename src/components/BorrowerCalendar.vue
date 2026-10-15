<script setup>
/*
 * Kalender jadwal peminjaman Borrower.
 *
 * Data asli dari GET /borrower/calendar (rentang satu bulan). Tiga jenis
 * event dibedakan lewat penanda warna: pengambilan (biru), rentang periode
 * (garis gelap), dan tenggat (oranye/merah bila terlambat). Seluruh perhitungan
 * hari memakai UTC karena backend mengirim tanggal sebagai `Y-m-d` hasil
 * konversi waktu tersimpan, sehingga tidak ada pergeseran hari antar zona.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronLeft, ChevronRight, CalendarClock, PackageCheck, RotateCcw } from '@lucide/vue'
import { apiGet, ApiError } from '../lib/http'
import { fmtDateTime, LOAN_TONES } from '../lib/format'
import { usePageMotion } from '../lib/motion'
import StatusBadge from './StatusBadge.vue'
import LoadingState from './LoadingState.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'

const KIND_META = {
  pickup: { label: 'Pengambilan', icon: PackageCheck },
  period: { label: 'Periode pinjam', icon: CalendarClock },
  due: { label: 'Tenggat kembali', icon: RotateCcw },
}

const monthFmt = new Intl.DateTimeFormat('id-ID', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

const now = new Date()
const cursor = ref({ year: now.getUTCFullYear(), month: now.getUTCMonth() })
const selected = ref(null)
const events = ref([])
const loading = ref(false)
const error = ref(null)

const root = ref(null)
const motion = usePageMotion(root)

const monthLabel = computed(() =>
  monthFmt.format(new Date(Date.UTC(cursor.value.year, cursor.value.month, 1))),
)

const todayKey = computed(() => toKey(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()))

const days = computed(() => {
  const { year, month } = cursor.value
  const first = Date.UTC(year, month, 1)
  const offset = (new Date(first).getUTCDay() + 6) % 7 // Senin dulu.
  const start = first - offset * 86400000

  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start + index * 86400000)
    return {
      key: toKey(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()),
      day: date.getUTCDate(),
      inMonth: date.getUTCMonth() === month,
      isToday:
        toKey(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()) === todayKey.value,
    }
  })
})

/* Penanda per tanggal: dipakai sel kalender, dibentuk sekali dari daftar event. */
const marks = computed(() => {
  const map = new Map()

  function cell(date) {
    if (!map.has(date)) {
      map.set(date, { pickup: false, due: false, overdue: false, dueSoon: false, period: false })
    }
    return map.get(date)
  }

  for (const event of events.value) {
    if (event.kind === 'period') {
      for (const date of datesBetween(event.start_date, event.end_date)) {
        cell(date).period = true
      }
    } else if (event.kind === 'pickup') {
      cell(event.date).pickup = true
    } else if (event.kind === 'due') {
      const target = cell(event.date)
      target.due = true
      target.overdue ||= Boolean(event.is_overdue)
      target.dueSoon ||= Boolean(event.is_due_soon)
    }
  }

  return map
})

const selectedEvents = computed(() => {
  if (!selected.value) {
    return []
  }

  return events.value
    .filter((event) => {
      if (event.kind === 'period') {
        return event.start_date <= selected.value && selected.value <= event.end_date
      }
      return event.date === selected.value
    })
    .sort((a, b) => rank(a) - rank(b))
})

async function load() {
  loading.value = true
  error.value = null

  const { year, month } = cursor.value
  const from = toKey(year, month, 1)
  const to = toKey(year, month + 1, 0)

  try {
    const payload = await apiGet(`/borrower/calendar?from=${from}&to=${to}`)
    events.value = payload.events ?? []
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat jadwal.'
    events.value = []
  } finally {
    loading.value = false
    motion.refresh()
  }
}

function moveMonth(step) {
  const next = new Date(Date.UTC(cursor.value.year, cursor.value.month + step, 1))
  cursor.value = { year: next.getUTCFullYear(), month: next.getUTCMonth() }

  const inView = selected.value?.startsWith(
    `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`,
  )
  if (!inView) {
    selected.value = toKey(next.getUTCFullYear(), next.getUTCMonth(), 1)
  }
}

function goToday() {
  const today = new Date()
  cursor.value = { year: today.getUTCFullYear(), month: today.getUTCMonth() }
  selected.value = todayKey.value
}

function selectDay(day) {
  selected.value = day.key
}

function loanPath(event) {
  return event.loan_id ? { name: 'loan-detail', params: { id: event.loan_id } } : null
}

function rank(event) {
  return event.kind === 'pickup' ? 0 : event.kind === 'period' ? 1 : 2
}

function toKey(year, month, day) {
  const date = new Date(Date.UTC(year, month, day))
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`
}

function datesBetween(from, to) {
  if (!from || !to) {
    return []
  }

  const result = []
  const cursorDate = new Date(`${from}T00:00:00Z`)
  const end = new Date(`${to}T00:00:00Z`)
  let guard = 0

  while (cursorDate <= end && guard < 366) {
    result.push(cursorDate.toISOString().slice(0, 10))
    cursorDate.setUTCDate(cursorDate.getUTCDate() + 1)
    guard++
  }

  return result
}

watch(cursor, load)

onMounted(() => {
  selected.value = todayKey.value
  load()
})
</script>

<template>
  <div ref="root" class="cal">
    <div class="cal__bar">
      <div class="cal__nav">
        <button
          type="button"
          class="cal__navbtn"
          aria-label="Bulan sebelumnya"
          @click="moveMonth(-1)"
        >
          <ChevronLeft :size="16" aria-hidden="true" />
        </button>
        <p class="cal__month" aria-live="polite">{{ monthLabel }}</p>
        <button
          type="button"
          class="cal__navbtn"
          aria-label="Bulan berikutnya"
          @click="moveMonth(1)"
        >
          <ChevronRight :size="16" aria-hidden="true" />
        </button>
      </div>

      <button type="button" class="btn btn--ghost cal__today" @click="goToday">Hari ini</button>
    </div>

    <ul class="cal__legend">
      <li><span class="cal__swatch cal__swatch--pickup" aria-hidden="true"></span> Pengambilan</li>
      <li>
        <span class="cal__swatch cal__swatch--period" aria-hidden="true"></span> Periode pinjam
      </li>
      <li><span class="cal__swatch cal__swatch--due" aria-hidden="true"></span> Tenggat kembali</li>
      <li>
        <span class="cal__swatch cal__swatch--overdue" aria-hidden="true"></span> Lewat tenggat
      </li>
    </ul>

    <LoadingState v-if="loading" label="Memuat jadwal" />

    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <div class="cal__body">
        <div class="cal__grid-wrap">
          <div class="cal__dows" aria-hidden="true">
            <span v-for="dow in ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']" :key="dow">{{
              dow
            }}</span>
          </div>

          <div class="cal__grid" role="group" aria-label="Jadwal bulan berjalan">
            <button
              v-for="day in days"
              :key="day.key"
              type="button"
              class="cal__day"
              :class="{
                'cal__day--out': !day.inMonth,
                'cal__day--today': day.isToday,
                'cal__day--selected': selected === day.key,
              }"
              :aria-pressed="selected === day.key"
              :aria-label="day.key"
              @click="selectDay(day)"
            >
              <span class="cal__dayno num">{{ day.day }}</span>
              <span class="cal__marks" aria-hidden="true">
                <span v-if="marks.get(day.key)?.period" class="cal__mark cal__mark--period"></span>
                <span v-if="marks.get(day.key)?.pickup" class="cal__mark cal__mark--pickup"></span>
                <span
                  v-if="marks.get(day.key)?.due"
                  class="cal__mark cal__mark--due"
                  :class="{
                    'cal__mark--overdue': marks.get(day.key)?.overdue,
                    'cal__mark--soon': marks.get(day.key)?.dueSoon && !marks.get(day.key)?.overdue,
                  }"
                ></span>
              </span>
            </button>
          </div>
        </div>

        <aside class="cal__panel" aria-live="polite">
          <p class="index-label"><b>JADWAL</b> / {{ selected }}</p>

          <EmptyState
            v-if="!selectedEvents.length"
            title="Tidak ada jadwal"
            hint="Belum ada pengambilan, periode pinjam, atau tenggat kembali pada tanggal ini."
          />

          <ul v-else class="cal__list">
            <li
              v-for="(event, index) in selectedEvents"
              :key="`${event.loan_id}-${event.kind}-${index}`"
              class="cal__item"
            >
              <div class="cal__item-top">
                <span class="cal__kind">
                  <component :is="KIND_META[event.kind].icon" :size="14" aria-hidden="true" />
                  {{ KIND_META[event.kind].label }}
                </span>
                <StatusBadge :tone="LOAN_TONES[event.status] ?? 'neutral'">
                  {{ event.status_label }}
                </StatusBadge>
              </div>

              <p class="cal__item-title">{{ event.title }}</p>

              <p class="cal__item-meta text-2">
                <template v-if="event.kind === 'period'">
                  {{ event.start_date }} sampai {{ event.end_date }}
                </template>
                <template v-else>{{ fmtDateTime(event.occurs_at) ?? event.date }}</template>
                <template v-if="event.tentative"> · jadwal tentatif, menunggu persetujuan</template>
                <template v-else-if="event.kind === 'due' && event.is_overdue">
                  · lewat tenggat</template
                >
                <template v-else-if="event.done"> · selesai</template>
              </p>

              <RouterLink v-if="loanPath(event)" :to="loanPath(event)" class="cal__link link-sweep">
                Lihat detail peminjaman
              </RouterLink>
            </li>
          </ul>
        </aside>
      </div>

      <p v-if="!events.length" class="cal__note text-2">
        Belum ada jadwal pada bulan ini. Pengajuan yang masih menunggu persetujuan tampil sebagai
        jadwal tentatif setelah dibuat.
      </p>
    </template>
  </div>
</template>

<style scoped>
.cal {
  display: grid;
  gap: 1.25rem;
}

.cal__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--ln-border);
}

.cal__nav {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.cal__navbtn {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 1px solid var(--ln-border);
  background: var(--ln-surface);
  color: var(--ln-text);
  cursor: pointer;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

.cal__navbtn:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.cal__month {
  min-width: 12ch;
  margin: 0;
  font-family: var(--ln-font-display);
  font-size: 1.25rem;
  letter-spacing: -0.03em;
  text-transform: capitalize;
}

.cal__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.cal__legend li {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.cal__swatch {
  display: inline-block;
  width: 14px;
  height: 4px;
}

.cal__swatch--pickup {
  background: var(--ln-info);
}

.cal__swatch--period {
  background: var(--ln-text);
}

.cal__swatch--due {
  background: var(--ln-warn);
}

.cal__swatch--overdue {
  background: var(--ln-err);
}

.cal__body {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
  gap: 1.5rem;
  align-items: start;
}

.cal__dows,
.cal__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.cal__dows {
  border-top: 1px solid var(--ln-border);
  border-left: 1px solid var(--ln-border);
}

.cal__dows span {
  padding: 0.4rem 0.25rem;
  border-right: 1px solid var(--ln-border);
  border-bottom: 1px solid var(--ln-border);
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-align: center;
  text-transform: uppercase;
}

.cal__grid {
  border-left: 1px solid var(--ln-border);
}

.cal__day {
  display: grid;
  gap: 0.3rem;
  align-content: start;
  min-height: 4.5rem;
  padding: 0.4rem;
  border: 0;
  border-right: 1px solid var(--ln-border);
  border-bottom: 1px solid var(--ln-border);
  background: var(--ln-surface);
  color: var(--ln-text);
  text-align: left;
  cursor: pointer;
  transition: background-color var(--ln-motion-fast) var(--ln-ease);
}

.cal__day:hover {
  background: var(--ln-surface-alt);
}

.cal__day--out .cal__dayno {
  color: var(--ln-n400);
}

.cal__day--today {
  outline: 1px solid var(--ln-border-strong);
  outline-offset: -1px;
}

.cal__day--selected {
  background: var(--ln-text);
  color: var(--ln-bg);
}

.cal__day--selected .cal__dayno {
  color: var(--ln-bg);
}

.cal__dayno {
  font-size: 0.8125rem;
}

.cal__marks {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
}

.cal__mark {
  display: inline-block;
  width: 100%;
  height: 3px;
  max-width: 14px;
}

.cal__mark--period {
  background: var(--ln-text-2);
}

.cal__day--selected .cal__mark--period {
  background: var(--ln-bg);
}

.cal__mark--pickup {
  background: var(--ln-info);
}

.cal__mark--due {
  background: var(--ln-warn);
}

.cal__mark--soon {
  background: var(--ln-err);
}

.cal__mark--overdue {
  background: var(--ln-err);
}

.cal__panel {
  display: grid;
  gap: 0.75rem;
  align-content: start;
  padding-left: 1.25rem;
  border-left: 1px solid var(--ln-border);
}

.cal__list {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--ln-border);
}

.cal__item {
  display: grid;
  gap: 0.35rem;
  padding: 0.875rem 0;
  border-bottom: 1px solid var(--ln-border);
}

.cal__item-top {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.cal__kind {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  color: var(--ln-text-2);
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.cal__item-title {
  margin: 0;
  font-weight: 600;
}

.cal__item-meta {
  margin: 0;
  font-size: 0.8125rem;
}

.cal__link {
  justify-self: start;
  color: var(--ln-text);
  font-size: 0.8125rem;
}

.cal__note {
  margin: 0;
  font-size: 0.875rem;
}

@media (max-width: 64rem) {
  .cal__body {
    grid-template-columns: 1fr;
  }

  .cal__panel {
    padding-top: 1.25rem;
    padding-left: 0;
    border-top: 1px solid var(--ln-border);
    border-left: 0;
  }
}

@media (max-width: 40rem) {
  .cal__day {
    min-height: 2.75rem;
    gap: 0.15rem;
    padding: 0.25rem;
  }

  .cal__dayno {
    font-size: 0.75rem;
  }

  .cal__mark {
    height: 2px;
  }

  .cal__legend {
    gap: 0.5rem 0.875rem;
  }

  .cal__month {
    min-width: 0;
    font-size: 1.0625rem;
  }
}
</style>
