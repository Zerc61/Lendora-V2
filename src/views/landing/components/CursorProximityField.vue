<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { clamp } from '../utils/motion'

gsap.registerPlugin(ScrollTrigger)

const region = ref<HTMLElement | null>(null)
let context: gsap.Context | undefined
let responsiveMotion: ReturnType<typeof gsap.matchMedia> | undefined

onMounted(() => {
  const root = region.value
  const target = root?.querySelector<HTMLElement>('.hero-title')
  if (!root || !target) return

  context = gsap.context(() => {
    responsiveMotion = gsap.matchMedia(root)
    responsiveMotion.add(
      '(min-width: 761px) and (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)',
      () => {
        const objects = gsap.utils.toArray<HTMLElement>('.proximity-symbol')
        const quickX = objects.map((object) =>
          gsap.quickTo(object, 'x', { duration: 0.42, ease: 'power3.out' }),
        )
        const quickY = objects.map((object) =>
          gsap.quickTo(object, 'y', { duration: 0.48, ease: 'power3.out' }),
        )
        const quickRotate = objects.map((object) =>
          gsap.quickTo(object, 'rotation', { duration: 0.48, ease: 'power3.out' }),
        )
        const positions = objects.map((object) => ({
          x: Number(object.dataset.anchorX ?? 0.5),
          y: Number(object.dataset.anchorY ?? 0.5),
          turn: Number(object.dataset.turn ?? 0),
        }))
        let sectionActive = false
        let interactionActive = false
        let entranceActive = false
        let pointerX = -1
        let pointerY = -1
        let frame = 0
        const openRadius = 112
        const closeRadius = 158

        gsap.set(objects, {
          autoAlpha: 0,
          scale: 0.76,
          xPercent: -50,
          yPercent: -50,
          x: 0,
          y: 0,
          rotation: 0,
        })

        const hide = () => {
          if (!interactionActive) return
          interactionActive = false
          entranceActive = false
          gsap.to(objects, {
            autoAlpha: 0,
            scale: 0.76,
            x: 0,
            y: 0,
            rotation: 0,
            duration: 0.28,
            stagger: 0.035,
            ease: 'power3.inOut',
            overwrite: true,
          })
        }

        const onFrame = () => {
          frame = 0
          if (!sectionActive || pointerX < 0) return
          const bounds = target.getBoundingClientRect()
          const textLines = Array.from(target.querySelectorAll<HTMLElement>('.hero-title-line'))
          const distance = textLines.reduce((nearest, line) => {
            const lineBounds = line.getBoundingClientRect()
            const dx = Math.max(lineBounds.left - pointerX, 0, pointerX - lineBounds.right)
            const dy = Math.max(lineBounds.top - pointerY, 0, pointerY - lineBounds.bottom)
            return Math.min(nearest, Math.hypot(dx, dy))
          }, Number.POSITIVE_INFINITY)
          if (!interactionActive && distance <= openRadius) {
            interactionActive = true
            entranceActive = true
            gsap.fromTo(
              objects,
              {
                autoAlpha: 0,
                scale: 0.58,
                x: (index) => (index === 0 ? -28 : 28),
                y: (index) => (index === 0 ? -22 : 22),
                rotation: (index) => (index === 0 ? -16 : 16),
              },
              {
                autoAlpha: 1,
                scale: 1,
                x: 0,
                y: 0,
                rotation: 0,
                duration: 0.54,
                stagger: 0.08,
                ease: 'power3.out',
                overwrite: true,
                onComplete: () => {
                  entranceActive = false
                },
              },
            )
          } else if (interactionActive && distance > closeRadius) {
            hide()
            return
          }
          if (!interactionActive || entranceActive) return

          const localX = (pointerX - bounds.left) / bounds.width
          const localY = (pointerY - bounds.top) / bounds.height
          positions.forEach((position, index) => {
            const symbolX = position.x * bounds.width
            const symbolY = position.y * bounds.height
            const vx = symbolX - localX * bounds.width
            const vy = symbolY - localY * bounds.height
            const symbolDistance = Math.hypot(vx, vy)
            const force = clamp(1 - symbolDistance / 148, 0, 1)
            const angle = Math.atan2(vy, vx)
            quickX[index]?.(Math.cos(angle) * force * 19)
            quickY[index]?.(Math.sin(angle) * force * 15)
            quickRotate[index]?.(position.turn + Math.cos(angle) * force * 5)
          })
        }

        const onPointerMove = (event: PointerEvent) => {
          if (!sectionActive || event.pointerType === 'touch') return
          pointerX = event.clientX
          pointerY = event.clientY
          if (!frame) frame = requestAnimationFrame(onFrame)
        }
        const onWindowLeave = (event: PointerEvent) => {
          if (!event.relatedTarget) {
            pointerX = -1
            pointerY = -1
            hide()
          }
        }
        const enterSection = () => {
          sectionActive = true
          if (pointerX >= 0 && !frame) frame = requestAnimationFrame(onFrame)
        }
        const leaveSection = () => {
          sectionActive = false
          pointerX = -1
          pointerY = -1
          hide()
        }

        const trigger = ScrollTrigger.create({
          trigger: root,
          start: 'top bottom',
          end: 'bottom top',
          onEnter: enterSection,
          onEnterBack: enterSection,
          onLeave: leaveSection,
          onLeaveBack: leaveSection,
        })
        if (ScrollTrigger.isInViewport(root, 0.05)) enterSection()
        window.addEventListener('pointermove', onPointerMove, { passive: true })
        window.addEventListener('pointerout', onWindowLeave, { passive: true })

        return () => {
          window.removeEventListener('pointermove', onPointerMove)
          window.removeEventListener('pointerout', onWindowLeave)
          if (frame) cancelAnimationFrame(frame)
          trigger.kill()
          gsap.killTweensOf(objects)
        }
      },
    )
  }, root)
})

onUnmounted(() => {
  responsiveMotion?.revert()
  context?.revert()
})
</script>

<template>
  <div ref="region" class="cursor-proximity-field">
    <slot />
    <div class="proximity-symbols" aria-hidden="true">
      <span
        class="proximity-symbol proximity-symbol-left"
        data-anchor-x="-0.05"
        data-anchor-y="0.08"
        data-turn="-5"
      >
        <svg viewBox="0 0 32 32" fill="none">
          <path d="M4 10.5h6l2-3h8l2 3h6v15H4v-15Z" />
          <circle cx="16" cy="17.5" r="5" />
          <path d="M24 13h1" />
        </svg>
      </span>
      <span
        class="proximity-symbol proximity-symbol-right"
        data-anchor-x="1.05"
        data-anchor-y="0.95"
        data-turn="5"
      >
        <svg viewBox="0 0 32 32" fill="none">
          <rect x="8" y="3.5" width="16" height="25" rx="1.5" />
          <path d="M13 7h6M14.5 24.5h3" />
        </svg>
      </span>
    </div>
  </div>
</template>
