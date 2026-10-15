/*
 * Gerak Lendora untuk aplikasi internal.
 *
 * Semua timeline memakai GSAP + ScrollTrigger dengan token dari
 * desain-logo/animation/animation-guide.md:
 *   cepat 120ms, dasar 180ms, lambat 280ms, easing cubic-bezier(.2,0,0,1).
 *
 * Aturan pakai:
 *   - Konten wajib terlihat tanpa animasi; animasi hanya menambah orientasi.
 *   - prefers-reduced-motion: seluruh helper jadi no-op dan konten tampil utuh.
 *   - Semua instance dibuat di dalam gsap.context(scope) sehingga pembersihan
 *     otomatis saat view di-unmount (tidak ada ScrollTrigger yang nyangkut).
 */
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { onBeforeUnmount, onMounted } from 'vue'

gsap.registerPlugin(ScrollTrigger)

export const ease = {
  standard: 'power3.out',
  entrance: 'power4.out',
  exit: 'power2.in',
}

export const duration = {
  fast: 0.12,
  base: 0.18,
  slow: 0.28,
}

export function reducedMotion() {
  return (
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

/**
 * Composable gerak per view.
 *
 * @param {import('vue').Ref<HTMLElement|null>} rootRef elemen scope view
 *
 * @example
 * const page = ref(null)
 * const motion = usePageMotion(page)
 * onMounted(async () => {
 *   await load()
 *   motion.reveal('.row', { stagger: 0.05 })
 * })
 */
export function usePageMotion(rootRef) {
  let ctx = null
  const pending = []

  function runInContext(fn) {
    fn({ gsap, ScrollTrigger })
  }

  function safe(fn) {
    if (reducedMotion()) return
    if (!ctx) {
      pending.push(fn)
      return
    }
    ctx.add(() => runInContext(fn))
  }

  onMounted(() => {
    if (!rootRef.value) return
    ctx = gsap.context(() => {}, rootRef.value)
    while (pending.length) {
      ctx.add(() => runInContext(pending.shift()))
    }
  })

  onBeforeUnmount(() => {
    ctx?.revert()
    ctx = null
  })

  return {
    /** Jalankan kode GSAP apa pun di dalam konteks view ini. */
    run: safe,

    /**
     * Reveal sekali saat elemen masuk viewport (opasitas + geser ke atas).
     * Elemen yang dituju harus sudah ada di DOM saat dipanggil.
     */
    reveal(target, options = {}) {
      const { y = 18, stagger = 0.05, duration: d = 0.5, delay = 0, start = 'top 88%' } = options
      safe(() => {
        const els = gsap.utils.toArray(target, rootRef.value)
        if (!els.length) return
        gsap.from(els, {
          y,
          autoAlpha: 0,
          duration: d,
          delay,
          stagger,
          ease: ease.standard,
          scrollTrigger: { trigger: els[0], start, once: true },
        })
      })
    },

    /**
     * Angka statistik menghitung dari 0 ke nilai akhir saat pertama tampil.
     * `el` harus elemen teks (biasanya <span class="num">).
     */
    countTo(el, value, options = {}) {
      const { duration: d = 1, format = defaultFormat } = options
      const target = Number(value)
      const finalText = format(Number.isFinite(target) ? target : 0)
      if (reducedMotion() || !ctx) {
        if (el) el.textContent = finalText
        return
      }
      if (!el || !Number.isFinite(target)) return
      ctx.add(() => {
        const state = { v: 0 }
        gsap.to(state, {
          v: target,
          duration: d,
          ease: 'power2.out',
          onUpdate: () => {
            el.textContent = format(Math.round(state.v))
          },
          onComplete: () => {
            el.textContent = finalText
          },
        })
      })
    },

    /** Garis progres yang mengikuti scroll (scrub) — dipakai untuk rail/timeline. */
    scrubProgress(target, trigger, options = {}) {
      const { start = 'top 80%', end = 'bottom 55%', axis = 'y' } = options
      safe(() => {
        const el = gsap.utils.toArray(target, rootRef.value)[0]
        const trig = gsap.utils.toArray(trigger, rootRef.value)[0] ?? el
        if (!el || !trig) return
        gsap.fromTo(
          el,
          axis === 'y'
            ? { scaleY: 0, transformOrigin: 'top center' }
            : { scaleX: 0, transformOrigin: 'left center' },
          {
            [axis === 'y' ? 'scaleY' : 'scaleX']: 1,
            ease: 'none',
            scrollTrigger: { trigger: trig, start, end, scrub: 0.6, invalidateOnRefresh: true },
          },
        )
      })
    },

    /** Parallax lembut untuk elemen dekoratif (angka indeks, simbol). */
    parallax(target, options = {}) {
      const { amount = 40, start = 'top bottom', end = 'bottom top' } = options
      safe(() => {
        const els = gsap.utils.toArray(target, rootRef.value)
        if (!els.length) return
        els.forEach((el) => {
          gsap.fromTo(
            el,
            { y: amount },
            {
              y: -amount,
              ease: 'none',
              scrollTrigger: { trigger: el, start, end, scrub: 0.8 },
            },
          )
        })
      })
    },

    /** Hitung ulang posisi ScrollTrigger setelah data/pelayoutan berubah. */
    refresh() {
      if (reducedMotion()) return
      ScrollTrigger.refresh()
    },

    gsap,
    ScrollTrigger,
  }
}

function defaultFormat(value) {
  return new Intl.NumberFormat('id-ID').format(value)
}
