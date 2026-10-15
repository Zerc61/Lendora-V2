<script setup lang="ts">
import { ArrowDown, ArrowRight, ClipboardCheck, RotateCcw, Search, Wrench } from '@lucide/vue'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { onMounted, onUnmounted, ref } from 'vue'
import CursorProximityField from './components/CursorProximityField.vue'
import LiquidEntrance from './components/LiquidEntrance.vue'
import SiteNav from './components/SiteNav.vue'
import VintageCameraViewer from './components/VintageCameraViewer.vue'
import { motion } from './utils/motion'
import './landing.css'

gsap.registerPlugin(ScrollTrigger)

const page = ref<HTMLElement | null>(null)
const theme = ref<'light' | 'dark'>(
  typeof document !== 'undefined' && document.documentElement.dataset.theme === 'dark'
    ? 'dark'
    : 'light',
)
const cameraActive = ref(false)
let heroHasRevealed = false
let animationContext: gsap.Context | undefined
let heroRevealContext: gsap.Context | undefined
let responsiveMotion: ReturnType<typeof gsap.matchMedia> | undefined
let heroEntryTimeline: gsap.core.Timeline | undefined

function setTheme(nextTheme: 'light' | 'dark') {
  theme.value = nextTheme
  document.documentElement.dataset.theme = nextTheme
  document.documentElement.style.colorScheme = nextTheme
  window.localStorage.setItem('lendora-theme', nextTheme)
}

function revealHero() {
  if (heroHasRevealed) return
  heroHasRevealed = true
  const root = page.value
  if (!root || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  heroRevealContext = gsap.context(() => {
    heroEntryTimeline = gsap
      .timeline({ defaults: { ease: motion.ease.standard } })
      .from('.hero-kicker', { x: -10, autoAlpha: 0, duration: 0.22 })
      .from(
        '.hero-title',
        { y: 14, autoAlpha: 0, duration: 0.32, ease: motion.ease.entrance },
        '-=0.12',
      )
      .from('.hero-copy', { y: 8, autoAlpha: 0, duration: 0.24 }, '-=0.14')
      .from('.hero-actions', { y: 6, autoAlpha: 0, duration: 0.2 }, '-=0.12')
      .from('.camera-viewer', { y: 10, autoAlpha: 0, duration: 0.36 }, '-=0.24')
  }, root)
}

const capabilities = [
  {
    number: '01',
    title: 'Katalog yang bisa ditelusuri',
    copy: 'Peminjam bisa melihat aset apa yang tersedia, di mana lokasinya, dan kapan bisa digunakan.',
    details: [
      'Cari berdasarkan nama dan deskripsi',
      'Saring dengan kategori dan lokasi',
      'Periksa ketersediaan untuk periode pilihan',
    ],
    audience: 'UNTUK PEMINJAM',
    icon: Search,
    scene: 'catalog',
    label: 'PENEMUAN ASET',
  },
  {
    number: '02',
    title: 'Permintaan sampai serah terima',
    copy: 'Peminjam mengajukan periode. Operator meninjau permintaan, lalu kedua pihak mengikuti status sampai aset kembali.',
    details: [
      'Periode wajib, alasan peminjaman opsional',
      'Operator dapat menyetujui atau menolak dengan alasan',
      'Timeline menunjukkan status dan langkah berikutnya',
    ],
    audience: 'PEMINJAM & OPERATOR',
    icon: ClipboardCheck,
    scene: 'request',
    label: 'ALUR PEMINJAMAN',
  },
  {
    number: '03',
    title: 'Kondisi aset tetap tercatat',
    copy: 'Pemeriksaan sebelum pengambilan dan setelah pengembalian menjaga kondisi aset tetap diketahui.',
    details: [
      'Catat kondisi, catatan, dan foto bila diperlukan',
      'Masalah dapat ditindaklanjuti sebagai issue',
      'Aset menunggu inspeksi sebelum kembali aktif',
    ],
    audience: 'UNTUK OPERATOR',
    icon: Wrench,
    scene: 'condition',
    label: 'KONDISI ASET',
  },
]

const steps = [
  { number: '01', label: 'Temukan', detail: 'Cari aset dan periksa jadwal ketersediaannya.' },
  { number: '02', label: 'Ajukan', detail: 'Kirim permintaan untuk periode yang dibutuhkan.' },
  {
    number: '03',
    label: 'Ambil',
    detail: 'Operator menyetujui dan mencatat kondisi saat serah terima.',
  },
  {
    number: '04',
    label: 'Kembalikan',
    detail: 'Aset diperiksa kembali dan riwayat kondisinya tersimpan.',
  },
]

onMounted(() => {
  document.documentElement.dataset.theme = theme.value
  document.documentElement.style.colorScheme = theme.value
  const root = page.value
  if (!root) return

  animationContext = gsap.context(() => {
    responsiveMotion = gsap.matchMedia(root)
    responsiveMotion.add(
      {
        reduceMotion: '(prefers-reduced-motion: reduce)',
        narrow: '(max-width: 760px)',
        wide: '(min-width: 761px)',
      },
      ({ reduceMotion, narrow }) => {
        if (reduceMotion) return

        const problemRows = gsap.utils.toArray<HTMLElement>('.problem-list > div')
        gsap.fromTo(
          problemRows,
          { x: (index) => (index % 2 === 0 ? -14 : 14), autoAlpha: 0.76 },
          {
            x: 0,
            autoAlpha: 1,
            stagger: 0.12,
            ease: 'none',
            scrollTrigger: {
              trigger: '.problem-content',
              start: 'top 78%',
              end: 'center 54%',
              scrub: 0.55,
              invalidateOnRefresh: true,
            },
          },
        )

        if (narrow) {
          const rail = root.querySelector<HTMLElement>('.capability-rail')
          const progressBar = root.querySelector<HTMLElement>('.capability-progress span')
          if (rail && progressBar) {
            const updateProgress = () => {
              const max = rail.scrollWidth - rail.clientWidth
              gsap.set(progressBar, { scaleX: max > 0 ? rail.scrollLeft / max : 0 })
            }
            rail.addEventListener('scroll', updateProgress, { passive: true })
            updateProgress()
            window.addEventListener('resize', updateProgress)
            return () => {
              rail.removeEventListener('scroll', updateProgress)
              window.removeEventListener('resize', updateProgress)
            }
          }
        } else {
          const rail = root.querySelector<HTMLElement>('.capability-rail')
          const track = root.querySelector<HTMLElement>('.capability-track')
          const progressBar = root.querySelector<HTMLElement>('.capability-progress span')
          const setProgress = progressBar ? gsap.quickSetter(progressBar, 'scaleX') : undefined

          if (rail && track) {
            gsap.set(rail, { overflow: 'hidden' })
            const horizontalStory = gsap.to(track, {
              x: () => -(track.scrollWidth - rail.clientWidth),
              ease: 'none',
              scrollTrigger: {
                trigger: rail,
                start: 'top top+=76',
                end: () => `+=${track.scrollWidth - rail.clientWidth}`,
                pin: true,
                scrub: 0.8,
                invalidateOnRefresh: true,
                anticipatePin: 1,
                onUpdate: (self) => setProgress?.(self.progress),
              },
            })

            gsap.utils.toArray<HTMLElement>('.capability-panel').forEach((panel) => {
              const copy = panel.querySelector<HTMLElement>('.capability-panel-copy')
              if (!copy) return
              gsap.fromTo(
                copy,
                { x: 12, autoAlpha: 0.82 },
                {
                  x: 0,
                  autoAlpha: 1,
                  ease: 'none',
                  scrollTrigger: {
                    trigger: panel,
                    containerAnimation: horizontalStory,
                    horizontal: true,
                    start: 'left 84%',
                    end: 'left 56%',
                    scrub: 0.35,
                    invalidateOnRefresh: true,
                  },
                },
              )
            })
          }
        }

        const workflowSteps = gsap.utils.toArray<HTMLElement>('.workflow-step')
        gsap.fromTo(
          workflowSteps,
          { x: (index) => (index % 2 === 0 ? -16 : 16), autoAlpha: 0.78 },
          {
            x: 0,
            autoAlpha: 1,
            stagger: 0.12,
            ease: 'none',
            scrollTrigger: {
              trigger: '.workflow-steps',
              start: 'top 82%',
              end: 'bottom 58%',
              scrub: 0.62,
              invalidateOnRefresh: true,
            },
          },
        )
        gsap.fromTo(
          '.workflow-track-progress',
          { scaleY: 0 },
          {
            scaleY: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: '.workflow-steps',
              start: 'top 74%',
              end: 'bottom 52%',
              scrub: 0.55,
              invalidateOnRefresh: true,
            },
          },
        )
      },
    )
  }, root)
})

onUnmounted(() => {
  heroEntryTimeline?.kill()
  heroRevealContext?.revert()
  responsiveMotion?.revert()
  animationContext?.revert()
})
</script>

<template>
  <div ref="page" class="page-shell min-h-screen bg-paper text-ink" :data-theme="theme">
    <a class="skip-link" href="#konten">Lewati ke konten</a>
    <SiteNav :theme="theme" @toggle-theme="setTheme(theme === 'light' ? 'dark' : 'light')" />
    <LiquidEntrance @complete.once="revealHero" @uncovered.once="cameraActive = true" />

    <main id="konten">
      <!-- Hero -->
      <section id="awal" class="hero section-wrap">
        <div class="hero-copy-block">
          <p class="hero-kicker">
            <span class="kicker-rule"></span> MANAJEMEN ASET &amp; PEMINJAMAN
          </p>
          <CursorProximityField>
            <h1 class="hero-title">
              <span class="hero-title-line">Aset terkelola.</span>
              <span class="hero-title-line hero-title-muted">Peminjaman jelas.</span>
            </h1>
          </CursorProximityField>
          <p class="hero-copy">
            Lendora membantu organisasi menemukan aset, memeriksa ketersediaannya, dan mengikuti
            setiap peminjaman sampai kembali.
          </p>
          <div class="hero-actions">
            <a class="button button-dark" href="/login"> Mulai sekarang </a>
            <a class="text-link" href="#fitur">
              Jelajahi fitur <ArrowRight :size="15" aria-hidden="true" />
            </a>
          </div>
          <div class="hero-index"><span>ASET DALAM SATU ALUR</span></div>
        </div>
        <div class="hero-visual">
          <VintageCameraViewer :active="cameraActive" />
          <div class="visual-caption">
            <span>KAMERA FILM VINTAGE · MODEL 3D</span>
            <span>SERET UNTUK MEMUTAR 360°</span>
          </div>
        </div>
        <a class="scroll-cue" href="#manfaat">
          <span>GULIR UNTUK MENJELAJAHI</span>
          <ArrowDown :size="14" aria-hidden="true" />
        </a>
      </section>

      <!-- Problem -->
      <section id="manfaat" class="problem-section">
        <div class="section-wrap problem-layout">
          <div class="section-heading">
            <p class="section-index">TANTANGAN</p>
            <h2>Pinjam aset seharusnya tidak jadi pekerjaan detektif.</h2>
          </div>
          <div class="problem-content">
            <p class="problem-lead">
              Saat catatan aset, jadwal peminjaman, dan kondisi tersimpan terpisah, jawaban
              sederhana pun sulit dicari.
            </p>
            <div class="problem-list">
              <div>
                <p>Aset mana yang tersedia untuk tanggal tertentu?</p>
                <span class="problem-mark" aria-hidden="true">?</span>
              </div>
              <div>
                <p>Siapa yang sedang menggunakannya?</p>
                <span class="problem-mark" aria-hidden="true">?</span>
              </div>
              <div>
                <p>Bagaimana kondisinya saat terakhir kembali?</p>
                <span class="problem-mark" aria-hidden="true">?</span>
              </div>
            </div>
          </div>
          <div class="problem-conclusion">
            <span class="conclusion-mark" aria-hidden="true">&#x2198;</span>
            <p>
              Satu ruang untuk melihat aset dan proses peminjamannya, dengan riwayat yang saling
              terhubung.
            </p>
          </div>
        </div>
      </section>

      <!-- Capabilities -->
      <section id="fitur" class="capability-section">
        <div class="section-wrap capability-top">
          <div>
            <p class="section-index">KEMAMPUAN</p>
            <h2>Informasi yang dibutuhkan,<br />di saat yang tepat.</h2>
          </div>
          <p class="section-intro">
            Dari pencarian pertama sampai perawatan, setiap bagian membantu orang tahu apa yang
            terjadi dan apa langkah berikutnya.
          </p>
        </div>
        <div
          class="capability-rail"
          tabindex="0"
          aria-label="Rangkaian kemampuan Lendora. Gulir ke bawah untuk bergerak ke samping, atau geser horizontal di layar sentuh."
        >
          <div class="capability-track">
            <article v-for="item in capabilities" :key="item.number" class="capability-panel">
              <div class="capability-panel-copy">
                <div class="capability-panel-meta">
                  <component :is="item.icon" :size="20" stroke-width="1.6" aria-hidden="true" />
                </div>
                <div class="capability-panel-description">
                  <p class="capability-audience">{{ item.audience }}</p>
                  <p class="capability-scene-label">{{ item.label }}</p>
                  <h3>{{ item.title }}</h3>
                  <p class="capability-panel-lead">{{ item.copy }}</p>
                  <ul class="capability-points">
                    <li v-for="detail in item.details" :key="detail">{{ detail }}</li>
                  </ul>
                </div>
              </div>
              <div
                class="capability-art"
                :class="`capability-art-${item.scene}`"
                aria-hidden="true"
              >
                <template v-if="item.scene === 'catalog'">
                  <div class="art-window-bar">
                    <span></span><span></span><span></span><b>KATALOG ASET</b>
                  </div>
                  <div class="art-search">
                    <Search :size="14" /><span>Cari nama atau deskripsi</span
                    ><span>&#x2318; K</span>
                  </div>
                  <div class="art-filter-row">
                    <span>STATUS</span><span>KATEGORI</span><span>LOKASI</span><span>PERIODE</span>
                  </div>
                  <div class="art-inventory-row">
                    <i>KM</i>
                    <span><b>Kamera mirrorless</b><small>Studio A &middot; Kamera</small></span>
                    <em>TERSEDIA</em>
                  </div>
                  <div class="art-inventory-row">
                    <i>PP</i>
                    <span
                      ><b>Proyektor portabel</b><small>Ruang 02 &middot; Presentasi</small></span
                    >
                    <em class="art-muted">DIPINJAM</em>
                  </div>
                  <div class="art-inventory-row">
                    <i>TV</i>
                    <span><b>Tripod video</b><small>Studio A &middot; Produksi</small></span>
                    <em>TERSEDIA</em>
                  </div>
                </template>
                <template v-else-if="item.scene === 'request'">
                  <div class="art-request-head">
                    <span>PERMINTAAN PEMINJAMAN</span>
                  </div>
                  <div class="art-request-title">Kamera mirrorless <span>KM-014</span></div>
                  <div class="art-request-dates">
                    <div><small>PERIODE</small><b>12 – 14 Mei</b></div>
                    <ArrowRight :size="15" />
                    <div><small>PEMINJAM</small><b>Tim produksi</b></div>
                  </div>
                  <div class="art-request-flow">
                    <span class="is-done"><i>&#x2713;</i> Diajukan</span>
                    <span class="is-done"><i>&#x2713;</i> Disetujui</span>
                    <span><i></i> Serah terima</span>
                    <span><i></i> Kembali</span>
                  </div>
                </template>
                <template v-else>
                  <div class="art-condition-head">
                    <span>PEMERIKSAAN ASET</span><span>KM-014</span>
                  </div>
                  <div class="art-condition-line">
                    <b>Kondisi fisik</b><span class="art-check">TERCATAT</span>
                  </div>
                  <div class="art-condition-line">
                    <b>Aksesori lengkap</b><span class="art-check">TERCATAT</span>
                  </div>
                  <div class="art-condition-line">
                    <b>Catatan setelah pakai</b>
                    <span class="art-issue">ADA CATATAN</span>
                  </div>
                  <div class="art-condition-note">
                    <Wrench :size="15" /><span>Ditindaklanjuti melalui issue &amp; perawatan</span>
                  </div>
                  <div class="art-condition-gate">
                    <span></span>Menunggu inspeksi sebelum aset aktif kembali
                  </div>
                </template>
                <span class="capability-art-index">{{ item.label }}</span>
              </div>
            </article>
          </div>
        </div>
        <div class="section-wrap capability-rail-footer">
          <div class="capability-progress"><span></span></div>
          <p>GULIR / GESER LANJUT <ArrowRight :size="13" aria-hidden="true" /></p>
          <div class="feature-note">
            <span class="note-line"></span>
            <p>Contoh antarmuka dan nama aset bersifat ilustratif.</p>
          </div>
        </div>
      </section>

      <!-- Workflow -->
      <section id="alur" class="workflow-section">
        <div class="section-wrap workflow-wrap">
          <div class="workflow-heading">
            <p class="section-index">ALUR PEMINJAMAN</p>
            <h2>Dari pencarian<br />sampai kembali.</h2>
            <p>
              Satu proses yang bisa diikuti peminjam dan operator, dengan kondisi aset diperiksa
              saat serah terima.
            </p>
          </div>
          <div class="workflow-steps">
            <div class="workflow-track" aria-hidden="true">
              <span class="workflow-track-base"></span>
              <span class="workflow-track-progress"></span>
            </div>
            <article v-for="step in steps" :key="step.number" class="workflow-step">
              <span class="step-node" aria-hidden="true"></span>
              <div>
                <h3>{{ step.label }}</h3>
                <p>{{ step.detail }}</p>
              </div>
            </article>
            <div class="workflow-aside">
              <span class="aside-symbol"><RotateCcw :size="18" aria-hidden="true" /></span>
              <p>
                <strong>Setelah kembali</strong><br />
                Jika ada masalah, aset dapat ditindaklanjuti melalui pencatatan issue dan perawatan.
              </p>
              <Wrench :size="16" aria-hidden="true" />
            </div>
          </div>
        </div>
      </section>

      <!-- Closing CTA -->
      <section class="closing-section section-wrap">
        <div class="closing-label">
          <span>LANGKAH BERIKUTNYA</span>
          <span>UNTUK ORGANISASI</span>
        </div>
        <div class="closing-main">
          <h2>Kenali alurnya.<br /><span>Mulai kelola aset.</span></h2>
          <div class="closing-actions">
            <a class="button button-light" href="/login"> Masuk ke aplikasi </a>
            <a class="button button-outline" href="/register">Daftar organisasi</a>
          </div>
        </div>
        <div class="closing-foot">
          <span>ASET · PEMINJAMAN · KONDISI</span>
          <ArrowDown :size="14" aria-hidden="true" />
          <span>LENDORA V2</span>
        </div>
      </section>
    </main>

    <footer class="site-footer section-wrap">
      <a class="footer-brand" href="#awal" aria-label="Lendora, kembali ke awal">
        <img src="/brand/lendora-lockup-inverse.svg" width="150" height="24" alt="Lendora" />
      </a>
      <p>Menjaga aset dan peminjaman tetap jelas.</p>
      <nav aria-label="Navigasi footer">
        <a href="#manfaat">Manfaat</a>
        <a href="#fitur">Fitur</a>
        <a href="#alur">Alur kerja</a>
        <a href="/login">Masuk</a>
        <a href="/register">Daftar</a>
      </nav>
      <span class="footer-copy">© LENDORA V2</span>
    </footer>
  </div>
</template>
