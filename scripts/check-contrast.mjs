#!/usr/bin/env node
/*
 * Cek kontras WCAG 2.1 untuk pasangan warna dari token
 * (src/styles/tokens.css), di tema terang dan gelap. Teks normal minimal
 * 4.5:1; batas komponen minimal 3:1.
 *
 * Tanpa dependensi. Jalankan dengan: node scripts/check-contrast.mjs
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

const source = readFileSync(resolve(root, 'src/styles/tokens.css'), 'utf8')

function readTokens(selector) {
  const start = source.indexOf(selector)

  if (start === -1) {
    return {}
  }

  const open = source.indexOf('{', start)
  const close = source.indexOf('}', open)
  const body = source.slice(open + 1, close)
  const tokens = {}

  for (const [, key, value] of body.matchAll(/(--ln-[\w-]+):\s*([^;]+);/g)) {
    tokens[key] = value.trim()
  }

  return tokens
}

function luminance(hex) {
  const value = hex.replace('#', '')
  const full =
    value.length === 3
      ? value
          .split('')
          .map((char) => char + char)
          .join('')
      : value
  const channels = [0, 2, 4].map((offset) => parseInt(full.slice(offset, offset + 2), 16) / 255)
  const linear = (channel) =>
    channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4

  return 0.2126 * linear(channels[0]) + 0.7152 * linear(channels[1]) + 0.0722 * linear(channels[2])
}

function contrast(first, second) {
  const [lighter, darker] = [luminance(first), luminance(second)].sort((a, b) => b - a)

  return (lighter + 0.05) / (darker + 0.05)
}

const light = readTokens(':root')
const dark = { ...light, ...readTokens("[data-theme='dark']") }

const pairs = [
  ['teks di latar', '--ln-text', '--ln-bg', 4.5],
  ['teks sekunder di latar', '--ln-text-2', '--ln-bg', 4.5],
  ['teks sekunder di permukaan alt', '--ln-text-2', '--ln-surface-alt', 4.5],
  ['label sukses', '--ln-ok', '--ln-ok-bg', 4.5],
  ['label peringatan', '--ln-warn', '--ln-warn-bg', 4.5],
  ['label galat', '--ln-err', '--ln-err-bg', 4.5],
  ['label info', '--ln-info', '--ln-info-bg', 4.5],
  ['teks terbalik', '--ln-inverse-fg', '--ln-inverse-bg', 4.5],
  ['batas komponen', '--ln-border-strong', '--ln-bg', 3],
  ['teks tersier (dekoratif)', '--ln-n600', '--ln-bg', 3],
]

let failures = 0

for (const [themeLabel, tokens] of [
  ['terang', light],
  ['gelap', dark],
]) {
  console.log(`\nTema ${themeLabel}`)

  for (const [label, foregroundKey, backgroundKey, minimum] of pairs) {
    const foreground = tokens[foregroundKey]
    const background = tokens[backgroundKey]

    if (!foreground?.startsWith('#') || !background?.startsWith('#')) {
      console.log(`FAIL  ${label.padEnd(30)} token warna tidak terbaca`)
      failures += 1
      continue
    }

    const value = contrast(foreground, background)
    const passed = value >= minimum

    if (!passed) {
      failures += 1
    }

    console.log(
      `${passed ? 'ok  ' : 'FAIL'}  ${label.padEnd(30)} ${value.toFixed(2)}:1 ` +
        `(minimal ${minimum}:1) ${foreground} di ${background}`,
    )
  }
}

console.log(`\n${failures} pasangan di bawah ambang.`)

if (failures > 0) {
  console.error('Kontras belum memenuhi ambang WCAG yang dipakai.')
  process.exit(1)
}

console.log('Semua pasangan warna memenuhi ambang.')
