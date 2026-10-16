#!/usr/bin/env node
/*
 * Uji logika dompet yang murni. Tanpa dependensi: cukup `node --test`, mengikuti
 * pola `scripts/check-*.mjs` yang sudah ada di repo.
 *
 * Jalankan dengan: npm run check:wallet
 */
import assert from 'node:assert/strict'
import test from 'node:test'

import { fmtPrice } from '../src/lib/format.js'
import { snapScriptUrl } from '../src/lib/snap.js'
import {
  FALLBACK_TOPUP_MAX,
  FALLBACK_TOPUP_MIN,
  isValidTopUpAmount,
  signedWalletAmount,
  topUpError,
  topUpLimits,
  walletExpiry,
} from '../src/lib/wallet.js'

const LIMITS = { min: 1000, max: 1000000 }

test('batas dari server dipakai apa adanya', () => {
  assert.deepEqual(topUpLimits({ topup_min: 5000, topup_max: 250000 }), {
    min: 5000,
    max: 250000,
  })
})

test('batas cadangan dipakai bila server tidak mengirim angka yang masuk akal', () => {
  assert.deepEqual(topUpLimits(null), { min: FALLBACK_TOPUP_MIN, max: FALLBACK_TOPUP_MAX })
  assert.deepEqual(topUpLimits({ topup_min: 0, topup_max: -1 }), {
    min: FALLBACK_TOPUP_MIN,
    max: FALLBACK_TOPUP_MAX,
  })
  assert.deepEqual(topUpLimits({ topup_min: 'abc', topup_max: null }), {
    min: FALLBACK_TOPUP_MIN,
    max: FALLBACK_TOPUP_MAX,
  })
})

test('nominal sah hanya bilangan bulat di dalam batas', () => {
  assert.equal(isValidTopUpAmount(50000, LIMITS), true)
  assert.equal(isValidTopUpAmount(1000, LIMITS), true)
  assert.equal(isValidTopUpAmount(1000000, LIMITS), true)
  // Kolom angka di HTML mengirim teks.
  assert.equal(isValidTopUpAmount('50000', LIMITS), true)

  assert.equal(isValidTopUpAmount(999, LIMITS), false)
  assert.equal(isValidTopUpAmount(1000001, LIMITS), false)
  assert.equal(isValidTopUpAmount(50000.5, LIMITS), false)
  assert.equal(isValidTopUpAmount('', LIMITS), false)
  assert.equal(isValidTopUpAmount('abc', LIMITS), false)
  assert.equal(isValidTopUpAmount(null, LIMITS), false)
})

test('pesan kesalahan memakai batas yang berlaku', () => {
  assert.equal(topUpError(50000, LIMITS), null)
  assert.equal(topUpError(500, LIMITS), `Nominal minimal ${fmtPrice(1000)}.`)
  assert.equal(topUpError(2000000, LIMITS), `Nominal maksimal ${fmtPrice(1000000)}.`)
  assert.equal(topUpError(500, { min: 2000, max: 3000 }), `Nominal minimal ${fmtPrice(2000)}.`)
})

test('nominal kosong dan bukan angka dibedakan pesannya', () => {
  assert.equal(topUpError('', LIMITS), 'Nominal belum diisi.')
  assert.equal(topUpError(null, LIMITS), 'Nominal belum diisi.')
  assert.equal(topUpError(undefined, LIMITS), 'Nominal belum diisi.')
  assert.equal(topUpError('abc', LIMITS), 'Nominal harus bilangan bulat rupiah.')
  assert.equal(topUpError(1500.5, LIMITS), 'Nominal harus bilangan bulat rupiah.')
})

test('tanda nominal mengikuti arah transaksi, bukan tanda angkanya', () => {
  assert.equal(signedWalletAmount({ direction: 'in', amount: 50000 }), `+ ${fmtPrice(50000)}`)
  assert.equal(signedWalletAmount({ direction: 'out', amount: 25000 }), `− ${fmtPrice(25000)}`)
  // Nominal di buku besar selalu positif; arah tak dikenal dibaca sebagai keluar.
  assert.equal(signedWalletAmount({ amount: 10000 }), `− ${fmtPrice(10000)}`)
  assert.equal(signedWalletAmount(null), `− ${fmtPrice(0)}`)
})

test('masa berlaku hanya ditampilkan untuk transaksi yang masih menunggu', () => {
  const expires = '2026-10-17T09:00:00+07:00'

  assert.equal(walletExpiry({ status: 'pending', expires_at: expires }), expires)
  assert.equal(walletExpiry({ status: 'success', expires_at: expires }), null)
  assert.equal(walletExpiry({ status: 'pending', expires_at: null }), null)
  assert.equal(walletExpiry(null), null)
})

test('skrip Snap mengikuti lingkungan Midtrans, bukan satu alamat tetap', () => {
  assert.match(snapScriptUrl(false), /sandbox\.midtrans\.com/)
  assert.match(snapScriptUrl(true), /app\.midtrans\.com/)
  assert.notEqual(snapScriptUrl(false), snapScriptUrl(true))
})
