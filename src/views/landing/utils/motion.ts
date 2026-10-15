export const motion = {
  ease: {
    standard: 'power3.out',
    entrance: 'power4.out',
  },
} as const

export function clamp(value: number, min: number, max: number) {
  return Math.min(max, Math.max(min, value))
}
