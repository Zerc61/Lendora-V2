<script setup lang="ts">
import { gsap } from 'gsap'
import { onMounted, onUnmounted, ref } from 'vue'

const emit = defineEmits<{ complete: []; uncovered: [] }>()
const visible = ref(true)
const layer = ref<HTMLElement | null>(null)
const mark = ref<SVGSVGElement | null>(null)
const lockup = ref<HTMLElement | null>(null)
const wordmark = ref<HTMLSpanElement | null>(null)
const cameraIcon = ref<SVGSVGElement | null>(null)
const phoneIcon = ref<SVGSVGElement | null>(null)
let context: gsap.Context | undefined
let master: gsap.core.Timeline | undefined
let safetyTimer = 0
let revealed = false
let uncovered = false

function beginPageReveal() {
  if (revealed) return
  revealed = true
  emit('complete')
}

function recover() {
  beginPageReveal()
  finishOverlay()
  master?.kill()
}

function finishOverlay() {
  if (uncovered) return
  uncovered = true
  visible.value = false
  emit('uncovered')
}

onMounted(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reducedMotion || !layer.value || !mark.value || !lockup.value || !wordmark.value) {
    recover()
    return
  }

  safetyTimer = window.setTimeout(recover, 4000)
  try {
    context = gsap.context(() => {
      const arcs = mark.value!.querySelectorAll<SVGPathElement>('.mark-arc')
      const orbitPath = mark.value!.querySelector<SVGPathElement>('.mark-orbit')!
      const orbitDot = mark.value!.querySelector<SVGCircleElement>('.mark-orbit-dot')!
      const travel = { progress: 0 }
      const orbitLength = orbitPath.getTotalLength()
      const pageObjects = [cameraIcon.value, phoneIcon.value].filter(
        (item): item is SVGSVGElement => Boolean(item),
      )

      gsap.set(arcs, { strokeDasharray: 28, strokeDashoffset: 28 })
      gsap.set(orbitDot, { autoAlpha: 0, attr: { cx: 27, cy: 16 } })
      gsap.set(wordmark.value, { autoAlpha: 0, x: 22 })
      gsap.set(lockup.value, { scale: 1, transformOrigin: '50% 50%' })
      gsap.set(pageObjects, {
        autoAlpha: 0,
        scale: 0.35,
        x: (index) => (index === 0 ? -24 : 24),
        y: (index) => (index === 0 ? -20 : 20),
        rotation: (index) => (index === 0 ? -14 : 14),
        transformOrigin: '50% 50%',
      })

      master = gsap
        .timeline({
          onComplete: () => {
            window.clearTimeout(safetyTimer)
            finishOverlay()
          },
        })
        .to(arcs, { strokeDashoffset: 0, duration: 0.8, stagger: 0.1, ease: 'power2.inOut' })
        .to(orbitDot, { autoAlpha: 1, duration: 0.1 }, '-=0.5')
        .to(
          travel,
          {
            progress: 1,
            duration: 0.85,
            ease: 'none',
            onUpdate: () => {
              const point = orbitPath.getPointAtLength(orbitLength * travel.progress)
              gsap.set(orbitDot, { attr: { cx: point.x, cy: point.y } })
            },
          },
          0,
        )
        .to(wordmark.value, { autoAlpha: 1, x: 0, duration: 0.36, ease: 'power2.out' }, '-=0.12')
        .to(lockup.value, { scale: 1.13, duration: 0.28, ease: 'power2.out' })
        .to(
          pageObjects,
          {
            autoAlpha: 1,
            scale: 1,
            x: 0,
            y: 0,
            rotation: 0,
            duration: 0.54,
            stagger: 0.08,
            ease: 'power3.out',
          },
          '<',
        )
        .to(lockup.value, { scale: 1, duration: 1, ease: 'power2.inOut' })
        .to(
          pageObjects,
          {
            autoAlpha: 0,
            scale: 0.35,
            x: (index) => (index === 0 ? -24 : 24),
            y: (index) => (index === 0 ? -20 : 20),
            rotation: (index) => (index === 0 ? -14 : 14),
            duration: 1,
            ease: 'power2.inOut',
          },
          '<',
        )
        .to({}, { duration: 0.28 })
        .call(beginPageReveal)
        .to(layer.value, { yPercent: -102, duration: 0.8, ease: 'power3.inOut' })
    }, layer.value)
  } catch (error) {
    console.error('[Lendora] Loading reveal could not initialize:', error)
    recover()
  }
})

onUnmounted(() => {
  window.clearTimeout(safetyTimer)
  master?.kill()
  context?.revert()
})
</script>

<template>
  <div v-if="visible" ref="layer" class="loading-screen" aria-hidden="true">
    <div class="loading-stage">
      <svg
        ref="cameraIcon"
        class="loading-object loading-object-camera"
        viewBox="0 0 32 32"
        fill="none"
        aria-hidden="true"
      >
        <path d="M4 10.5h6l2-3h8l2 3h6v15H4v-15Z" />
        <circle cx="16" cy="17.5" r="5" />
        <path d="M24 13h1" />
      </svg>
      <svg
        ref="phoneIcon"
        class="loading-object loading-object-phone"
        viewBox="0 0 32 32"
        fill="none"
        aria-hidden="true"
      >
        <rect x="8" y="3.5" width="16" height="25" rx="1.5" />
        <path d="M13 7h6M14.5 24.5h3" />
      </svg>
      <div class="loading-lockup" ref="lockup">
        <svg
          ref="mark"
          class="loading-mark"
          viewBox="0 0 32 32"
          role="img"
          aria-label="Simbol Lendora bergerak"
        >
          <g
            class="mark-arcs"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
          >
            <path class="mark-arc" d="M5.66 12.24A11 11 0 0 1 26.34 12.24" />
            <path class="mark-arc" d="M26.34 19.76A11 11 0 0 1 5.66 19.76" />
          </g>
          <path
            class="mark-orbit"
            d="M27 16 A11 11 0 1 1 5 16 A11 11 0 1 1 27 16"
            fill="none"
            stroke="none"
          />
          <circle class="mark-orbit-dot" cx="27" cy="16" r="2.6" fill="currentColor" />
        </svg>
        <span ref="wordmark" class="loading-screen-wordmark">Lendora</span>
      </div>
    </div>
  </div>
</template>
