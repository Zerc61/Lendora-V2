<script setup>
/*
 * Grid statistik dengan angka menghitung (count-up) saat masuk viewport.
 * Seluruh sel memakai garis rule, tanpa bayangan. Sel yang punya `to`
 * menjadi tautan dan berperilaku hover-inversion.
 */
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { gsap } from 'gsap'
import { usePageMotion } from '../../lib/motion'

const props = defineProps({
  items: { type: Array, required: true },
})

const root = ref(null)
const numberEls = ref([])
const motion = usePageMotion(root)

function setNumberRef(el, index) {
  if (el) numberEls.value[index] = el
}

onMounted(() => {
  motion.run(() => {
    gsap.from('.stat', {
      y: 16,
      autoAlpha: 0,
      duration: 0.45,
      stagger: 0.06,
      ease: 'power3.out',
      scrollTrigger: { trigger: root.value, start: 'top 88%', once: true },
    })
  })
  props.items.forEach((item, index) => {
    motion.countTo(numberEls.value[index], item.value, { duration: 1 })
  })
})
</script>

<template>
  <div ref="root" class="stats">
    <component
      :is="item.to ? RouterLink : 'div'"
      v-for="(item, index) in items"
      :key="item.label"
      class="stat"
      :to="item.to"
    >
      <span class="stat__value num">
        <span :ref="(el) => setNumberRef(el, index)">{{ item.value ?? 0 }}</span
        ><span v-if="item.suffix" class="stat__suffix">{{ item.suffix }}</span>
      </span>
      <span class="stat__label">{{ item.label }}</span>
      <span v-if="item.meta" class="stat__meta">{{ item.meta }}</span>
    </component>
  </div>
</template>

<style scoped>
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  border-top: 1px solid var(--ln-border);
  border-left: 1px solid var(--ln-border);
}

.stat {
  display: grid;
  gap: 0.375rem;
  align-content: start;
  min-height: 8.5rem;
  padding: 1rem;
  border-right: 1px solid var(--ln-border);
  border-bottom: 1px solid var(--ln-border);
  color: var(--ln-text);
  text-decoration: none;
  transition:
    background-color var(--ln-motion-fast) var(--ln-ease),
    color var(--ln-motion-fast) var(--ln-ease);
}

a.stat:hover {
  background: var(--ln-text);
  color: var(--ln-bg);
}

a.stat:hover .stat__label,
a.stat:hover .stat__meta {
  color: var(--ln-bg);
}

.stat__value {
  font-family: var(--ln-font-display);
  font-size: clamp(2rem, 3.2vw, 3rem);
  font-weight: 500;
  letter-spacing: -0.04em;
  line-height: 1;
}

.stat__suffix {
  font-size: 0.5em;
  margin-left: 0.15em;
}

.stat__label {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--ln-text);
}

.stat__meta {
  font-family: var(--ln-font-mono);
  font-size: var(--ln-micro);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ln-text-2);
}

@media (max-width: 40rem) {
  .stat {
    min-height: 6.5rem;
    padding: 0.875rem;
  }

  .stat__value {
    font-size: 1.75rem;
  }
}
</style>
